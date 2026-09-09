<?php
declare(strict_types=1);

namespace App\Core;

/**
 * 请求封装：JSON body、Header、客户端 IP。
 */
final class Request
{
    private ?array $body = null;

    public function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    /** 解析 JSON 请求体；非法 JSON 返回空数组 */
    public function body(): array
    {
        if ($this->body === null) {
            $raw = file_get_contents('php://input') ?: '';
            $decoded = json_decode($raw, true);
            $this->body = is_array($decoded) ? $decoded : [];
        }
        return $this->body;
    }

    /** JSON body 字段（回退 query string） */
    public function param(string $key, $default = null)
    {
        $body = $this->body();
        if (array_key_exists($key, $body)) {
            return $body[$key];
        }
        return $_GET[$key] ?? $_POST[$key] ?? $default;
    }

    public function header(string $name): string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return (string) ($_SERVER[$key] ?? '');
    }

    public function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
