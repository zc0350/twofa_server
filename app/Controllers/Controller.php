<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Tokens;

/**
 * API 控制器基类：JSON 失败响应与 Bearer 鉴权。
 */
abstract class Controller
{
    /** @return never */
    protected function fail(int $code, string $error): array
    {
        http_response_code($code);
        $result = ['error' => $error];
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }

    protected function json(array $data, int $code = 200): array
    {
        http_response_code($code);
        return $data;
    }

    protected function authUserId(Request $request): ?int
    {
        return Tokens::authenticate($request);
    }

    protected function issueToken(int $userId): string
    {
        return Tokens::issue($userId);
    }
}
