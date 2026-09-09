<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Cache;
use App\Core\Database;
use App\Core\Request;

/**
 * 加密保险库：每用户一条记录（version 单调递增 + 端到端密文 + 客户端 KDF 盐）。
 * 服务器对 cipher 内容零知识——只做"读最新 / 乐观并发写 / 历史归档"。
 */
final class VaultController extends Controller
{
    private const MAX_CIPHER_BYTES = 4 * 1024 * 1024;
    private const HISTORY_KEEP_COUNT = 10;
    private const HISTORY_KEEP_DAYS = 0;
    private const PUSH_RATE_LIMIT = 10;      // 单用户每分钟推送上限
    private const PUSH_RATE_WINDOW = 60;

    public function get(Request $request): array
    {
        $userId = $this->authUserId($request);
        if ($userId === null) {
            return $this->fail(401, '未登录或登录已过期');
        }

        $vault = Database::fetchOne('SELECT version, kdf_salt, cipher FROM vaults WHERE user_id = ?', [$userId]);

        return [
            'version' => $vault ? (int) $vault['version'] : 0,
            'cipher'  => $vault ? (string) $vault['cipher'] : null,
            'kdfSalt' => $vault ? (string) ($vault['kdf_salt'] ?? '') : '',
        ];
    }

    public function push(Request $request): array
    {
        $userId = $this->authUserId($request);
        if ($userId === null) {
            return $this->fail(401, '未登录或登录已过期');
        }

        // 推送频率限制（防异常客户端或恶意刷推）
        $pushKey = 'vault_push_' . $userId;
        if (Cache::increment($pushKey, self::PUSH_RATE_WINDOW) > self::PUSH_RATE_LIMIT) {
            return $this->fail(429, '推送过于频繁，请稍后再试');
        }

        $baseVersion = (int) $request->param('baseVersion', -1);
        $cipher      = (string) $request->param('cipher', '');
        $deviceId    = mb_substr(trim((string) $request->param('deviceId', '')), 0, 64);
        $kdfSalt     = strtolower(trim((string) $request->param('kdfSalt', '')));

        if ($cipher === '' || strlen($cipher) > self::MAX_CIPHER_BYTES) {
            return $this->fail(422, '密文非法或超出大小限制');
        }
        if (base64_decode($cipher, true) === false) {
            return $this->fail(422, '密文不是合法的 base64 编码');
        }
        if ($kdfSalt !== '' && !preg_match('/^[0-9a-f]{32}$/', $kdfSalt)) {
            return $this->fail(422, 'kdfSalt 非法');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT version, kdf_salt, cipher FROM vaults WHERE user_id = ? FOR UPDATE');
            $stmt->execute([$userId]);
            $vault = $stmt->fetch() ?: null;
            $current = $vault ? (int) $vault['version'] : 0;

            if ($baseVersion !== $current) {
                $pdo->rollBack();
                http_response_code(409);
                return [
                    'error'   => 'version_conflict',
                    'version' => $current,
                    'cipher'  => $vault ? (string) $vault['cipher'] : null,
                    'kdfSalt' => $vault ? (string) ($vault['kdf_salt'] ?? '') : '',
                ];
            }

            $now = date('Y-m-d H:i:s');
            if ($vault) {
                // 归档旧版本（服务端安全网）；内容未变化的重复推送不重复归档；
                // 回滚重推时先清除同版本旧归档（唯一键防冲突）
                if ($cipher !== (string) $vault['cipher']) {
                    Database::exec(
                        'DELETE FROM vault_history WHERE user_id = ? AND version = ?',
                        [$userId, $current]
                    );
                    Database::exec(
                        'INSERT INTO vault_history (user_id, version, kdf_salt, cipher, created_at) VALUES (?, ?, ?, ?, ?)',
                        [$userId, $current, ($vault['kdf_salt'] ?? null), (string) $vault['cipher'], $now]
                    );
                }
                Database::exec(
                    'UPDATE vaults SET version = ?, kdf_salt = ?, cipher = ?, updated_at = ? WHERE user_id = ?',
                    [$current + 1, $kdfSalt !== '' ? $kdfSalt : ($vault['kdf_salt'] ?? null), $cipher, $now, $userId]
                );
                $newVersion = $current + 1;
            } else {
                Database::exec(
                    'INSERT INTO vaults (user_id, version, kdf_salt, cipher, updated_at) VALUES (?, ?, ?, ?, ?)',
                    [$userId, 1, $kdfSalt !== '' ? $kdfSalt : null, $cipher, $now]
                );
                $newVersion = 1;
            }

            if ($deviceId !== '') {
                $device = Database::fetchOne(
                    'SELECT id FROM devices WHERE user_id = ? AND device_id = ?',
                    [$userId, $deviceId]
                );
                if ($device) {
                    Database::exec('UPDATE devices SET last_seen_at = ? WHERE id = ?', [$now, (int) $device['id']]);
                } else {
                    Database::exec(
                        'INSERT INTO devices (user_id, device_id, last_seen_at) VALUES (?, ?, ?)',
                        [$userId, $deviceId, $now]
                    );
                }
            }

            $pdo->commit();

            // 历史清理（尽力而为）：保留最近 10 个版本或 30 天
            $this->pruneHistory($userId);

            return ['version' => $newVersion];
        } catch (\Throwable $e) {
            $pdo->rollBack();
            return $this->fail(500, '服务器内部错误');
        }
    }

    /**
     * 历史版本：不带 version 返回归档列表（版本/时间/盐，不含密文）；
     * 带 ?version=N 返回该版本的完整密文。
     */
    public function history(Request $request): array
    {
        $userId = $this->authUserId($request);
        if ($userId === null) {
            return $this->fail(401, '未登录或登录已过期');
        }

        $version = $request->param('version');
        if ($version !== null && $version !== '') {
            $row = Database::fetchOne(
                'SELECT version, kdf_salt, cipher, created_at FROM vault_history WHERE user_id = ? AND version = ?',
                [$userId, (int) $version]
            );
            if ($row === null) {
                return $this->fail(404, '历史版本不存在或已被清理');
            }
            return [
                'version'     => (int) $row['version'],
                'kdfSalt'     => (string) ($row['kdf_salt'] ?? ''),
                'cipher'      => (string) $row['cipher'],
                'createdAtMs' => (int) (strtotime((string) $row['created_at']) * 1000),
            ];
        }

        $rows = Database::fetchAll(
            'SELECT version, kdf_salt, created_at FROM vault_history WHERE user_id = ? ORDER BY version DESC',
            [$userId]
        );

        return [
            'items' => array_map(static fn (array $r) => [
                'version'     => (int) $r['version'],
                'kdfSalt'     => (string) ($r['kdf_salt'] ?? ''),
                'createdAtMs' => (int) (strtotime((string) $r['created_at']) * 1000),
            ], $rows),
        ];
    }

    private function pruneHistory(int $userId): void
    {
        try {
            $rows = Database::fetchAll(
                'SELECT version FROM vault_history WHERE user_id = ? ORDER BY version DESC LIMIT ' . (self::HISTORY_KEEP_COUNT + 1),
                [$userId]
            );
            if (count($rows) > self::HISTORY_KEEP_COUNT) {
                $oldestKeep = (int) min(array_column($rows, 'version'));
                Database::exec(
                    'DELETE FROM vault_history WHERE user_id = ? AND version < ?',
                    [$userId, $oldestKeep]
                );
            }
            //长期保留，不删了。
            if (self::HISTORY_KEEP_DAYS > 1)
            Database::exec(
                'DELETE FROM vault_history WHERE user_id = ? AND created_at < ?',
                [$userId, date('Y-m-d H:i:s', time() - self::HISTORY_KEEP_DAYS * 86400)]
            );
        } catch (\Throwable $e) {
            // 清理失败不影响推送，留待下次
        }
    }
}
