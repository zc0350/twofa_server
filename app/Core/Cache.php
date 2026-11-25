<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * 基于文件的轻量缓存（零依赖）。
 * 用于：注册验证码冷却/限次、待验证注册暂存、登录失败计数、推送频率限制。
 * 数据存于 runtime/cache/（自动创建），KEY → {expires, value} JSON。
 */
final class Cache
{
    private static ?string $dir = null;

    private static function dir(): string
    {
        if (self::$dir === null) {
            self::$dir = SERVER_ROOT . '/runtime/cache';
            if (!is_dir(self::$dir)) {
                mkdir(self::$dir, 0775, true);
            }
        }
        return self::$dir;
    }

    private static function file(string $key): string
    {
        return self::dir() . '/' . hash('sha256', $key) . '.cache';
    }

    /** @return mixed|null */
    public static function get(string $key)
    {
        $file = self::file($key);
        if (!is_file($file)) {
            return null;
        }
        $raw = json_decode((string) file_get_contents($file), true);
        if (!is_array($raw) || !isset($raw['expires'], $raw['value'])) {
            @unlink($file);
            return null;
        }
        if ($raw['expires'] !== 0 && $raw['expires'] < time()) {
            @unlink($file);
            return null;
        }
        return $raw['value'];
    }

    /** $ttl 秒后过期；0 表示永不过期 */
    public static function set(string $key, $value, int $ttl): void
    {
        file_put_contents(
            self::file($key),
            json_encode(['expires' => $ttl > 0 ? time() + $ttl : 0, 'value' => $value], JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    public static function delete(string $key): void
    {
        @unlink(self::file($key));
    }

    /** 原子递增（flock 文件锁防并发丢失）；带过期时间 */
    public static function increment(string $key, int $ttl): int
    {
        $file = self::file($key);
        $dir = self::dir();
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $fp = fopen($file . '.lock', 'c');
        if (!$fp) {
            // 文件锁不可用时退化为非原子操作
            $current = (int) (self::get($key) ?? 0);
            $new = $current + 1;
            self::set($key, $new, $ttl);
            return $new;
        }
        flock($fp, LOCK_EX);
        $current = (int) (self::get($key) ?? 0);
        $new = $current + 1;
        self::set($key, $new, $ttl);
        flock($fp, LOCK_UN);
        fclose($fp);
        return $new;
    }

    /**
     * 原子读-改-写（flock 独占锁）。
     *
     * 回调签名：fn(mixed $value, int $expires, mixed &$write): mixed
     *   - $value   当前值，键不存在或已过期时为 null
     *   - $expires 当前绝对过期时间戳（0 = 永不过期；不存在/已过期时为 0）
     *   - &$write  写回指令：null = 不写回；'DELETE' = 删除；
     *              ['value' => 新值, 'expires' => 绝对过期时间戳] = 写回
     * 回调返回值原样透传，业务层据此决定响应。
     *
     * 用途：验证码「检查尝试次数 → 比对 → 递增 → 删除」必须在同一临界区内完成，
     * 否则并发请求同时读到旧计数，会绕过尝试次数上限。
     */
    public static function update(string $key, callable $fn)
    {
        $file = self::file($key);
        $fp = fopen($file . '.lock', 'c');
        if (!$fp) {
            // 文件锁不可用时退化为非原子读-改-写（与 increment 同策略）
            [$value, $expires] = self::readLockedEntry($file);
            $write = null;
            $result = $fn($value, $expires, $write);
            self::applyWrite($file, $write);
            return $result;
        }
        flock($fp, LOCK_EX);
        try {
            [$value, $expires] = self::readLockedEntry($file);
            $write = null;
            $result = $fn($value, $expires, $write);
            self::applyWrite($file, $write);
            return $result;
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    /** @return array{0: mixed, 1: int} [值, 绝对过期时间戳] */
    private static function readLockedEntry(string $file): array
    {
        $raw = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $value = is_array($raw) && array_key_exists('value', $raw) ? $raw['value'] : null;
        $expires = is_array($raw) && isset($raw['expires']) ? (int) $raw['expires'] : 0;
        if ($expires !== 0 && $expires < time()) {
            @unlink($file);
            return [null, 0];
        }
        return [$value, $expires];
    }

    /** @param mixed $write */
    private static function applyWrite(string $file, $write): void
    {
        if ($write === 'DELETE') {
            @unlink($file);
            return;
        }
        if (is_array($write) && array_key_exists('value', $write)) {
            $expires = (int) ($write['expires'] ?? 0);
            file_put_contents(
                $file,
                json_encode(['expires' => $expires, 'value' => $write['value']], JSON_UNESCAPED_UNICODE),
                LOCK_EX
            );
        }
    }
}
