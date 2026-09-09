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
}
