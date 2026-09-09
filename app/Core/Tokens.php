<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Bearer 令牌签发与校验（令牌仅存 SHA-256 哈希，30 天有效）。
 */
final class Tokens
{
    private const TTL_DAYS = 30;

    public static function issue(int $userId): string
    {
        $token = bin2hex(random_bytes(32));
        Database::exec(
            'INSERT INTO user_tokens (user_id, token_hash, expires_at, created_at) VALUES (?, ?, ?, ?)',
            [$userId, hash('sha256', $token), date('Y-m-d H:i:s', time() + self::TTL_DAYS * 86400), date('Y-m-d H:i:s')]
        );
        return $token;
    }

    /** 校验 Authorization: Bearer <token>；有效返回 user_id，否则 null */
    public static function authenticate(Request $request): ?int
    {
        $token = $request->header('authorization');
        if (preg_match('/^Bearer\s+(\S+)$/i', $token, $m)) {
            $token = $m[1];
        }
        // 令牌应为 64 位 hex
        if (strlen($token) !== 64 || !ctype_xdigit($token)) {
            return null;
        }
        $row = Database::fetchOne(
            'SELECT user_id FROM user_tokens WHERE token_hash = ? AND expires_at > ?',
            [hash('sha256', $token), date('Y-m-d H:i:s')]
        );
        return $row ? (int) $row['user_id'] : null;
    }

    /** 清理某用户的过期令牌（登录时顺带执行） */
    public static function deleteExpired(int $userId): void
    {
        Database::exec(
            'DELETE FROM user_tokens WHERE user_id = ? AND expires_at < ?',
            [$userId, date('Y-m-d H:i:s')]
        );
    }

    /** 撤销某用户全部令牌（修改/重置密码后调用，所有已登录端强制下线） */
    public static function revokeAll(int $userId): void
    {
        Database::exec('DELETE FROM user_tokens WHERE user_id = ?', [$userId]);
    }
}
