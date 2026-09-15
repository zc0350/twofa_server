<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use RuntimeException;

/**
 * PDO 单例，双驱动：
 *  - MySQL（默认，线上生产）：凭据从 .env 读取（DB_HOST/DB_PORT/DB_NAME/DB_USER/DB_PASS）
 *  - SQLite（可选，轻量自部署）：DB_DRIVER=sqlite，单文件库，默认 runtime/twofa.sqlite，
 *    首次连接自动建表（sql/init.sqlite.sql，幂等），WAL 模式提升并发。
 * fetchOne/fetchAll/exec 对两种驱动透明，调用方无需感知差异。
 */
final class Database
{
    private static ?PDO $pdo = null;

    /** 当前驱动（小写）：mysql / sqlite */
    public static function driver(): string
    {
        return strtolower((string) Env::get('DB_DRIVER', 'mysql'));
    }

    public static function isSqlite(): bool
    {
        return self::driver() === 'sqlite';
    }

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        if (self::isSqlite()) {
            $file = (string) Env::get('DB_FILE', SERVER_ROOT . '/runtime/twofa.sqlite');
            $dir = dirname($file);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            try {
                self::$pdo = new PDO('sqlite:' . $file, null, null, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
                // WAL：读写并发更好（读不阻塞写日志），SQLite 3.7+ 内置
                self::$pdo->exec('PRAGMA journal_mode = WAL');
                self::$pdo->exec('PRAGMA synchronous = NORMAL');
            } catch (\PDOException $e) {
                throw new RuntimeException('数据库连接失败：' . $e->getMessage());
            }
            self::ensureSqliteSchema();
            return self::$pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            Env::get('DB_HOST', '127.0.0.1'),
            Env::get('DB_PORT', '3306'),
            Env::get('DB_NAME', 'twofa_sync')
        );
        try {
            self::$pdo = new PDO($dsn, Env::get('DB_USER', 'twofa'), Env::get('DB_PASS', ''), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (\PDOException $e) {
            throw new RuntimeException('数据库连接失败：' . $e->getMessage());
        }
        return self::$pdo;
    }

/**
     * SQLite 模式：首次连接自动执行建表脚本（幂等，CREATE TABLE IF NOT EXISTS）。
     * 与部署 sql/init.sqlite.sql；解析要点：中文注释里的全角分号不影响拆分，
     * 注释行整行剔除后再按英文分号切分（PDO 单次只执行一条语句）。
     */
    private static function ensureSqliteSchema(): void
    {
        $file = SERVER_ROOT . '/sql/init.sqlite.sql';
        if (!is_file($file)) {
            return;
        }
        try {
            // 注意：不能用 \R 按行分割（\x85 是半角 NEL 控制符，且 UTF-8 "兼"等汉字
            // 的字节序列里可能含 \x85 的）,否则会在汉字中间被切断。JSON 文档统一
            // 用 \n 分割。
            $clean = '';
            foreach (explode("\n", (string) file_get_contents($file)) as $line) {
                $line = trim($line);
                if ($line === '' || strncmp($line, '--', 2) === 0) {
                    continue;
                }
                $clean .= $line . "\n";
            }
            foreach (preg_split('/;\s*/', $clean) as $stmt) {
                $stmt = trim($stmt);
                if ($stmt !== '') {
                    self::$pdo->exec($stmt);
                }
            }
        } catch (\PDOException $e) {
            // 建表失败（如权限/句法）不阻断启动，让后续查询自行暴露问题
            error_log('[SQLite] 初始化建表失败: ' . $e->getMessage());
        }
    }

    /** @return array<string, mixed>|null */
    public static function fetchOne(string $sql, array $params = []): ?array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    public static function fetchAll(string $sql, array $params = []): array
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return int|bool */
    public static function exec(string $sql, array $params = [])
    {
        $stmt = self::pdo()->prepare($sql);
        return $stmt->execute($params);
    }
}