<?php
/**
 * 2FA 同步服务 — 前端控制器（零依赖原生 PHP MVC）
 *
 * 所有 HTTP 请求经此入口分发。生产环境将 nginx/Apache 的文档根指向 public/，
 * 或使用仓库根目录的 router.php 以 PHP 内置服务器开发调试：
 *   php -S 0.0.0.0:8000 router.php
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0'); // 生产不向客户端输出 PHP 错误详情；错误进日志

define('SERVER_ROOT', dirname(__DIR__));

// .env 加载（先于一切配置读取）
require SERVER_ROOT . '/app/Core/Env.php';
\App\Core\Env::load(SERVER_ROOT . '/.env');

// PSR-4 风格自动加载：App\ → app/
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $file = SERVER_ROOT . '/app/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

// 时区
date_default_timezone_set(\App\Core\Env::get('TIMEZONE', 'Asia/Shanghai'));

// CORS（原生 App 不经过浏览器不受 CORS 约束；浏览器形态仅用于联调）
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Max-Age: 86400');
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// 路由表：[方法, 路径, 控制器@方法]
$routes = [
    ['GET', '/', 'IndexController@index'],
    ['GET', '/source', 'IndexController@source'],
    ['POST', '/v1/auth/register', 'AuthController@register'],
    ['POST', '/v1/auth/verify',   'AuthController@verify'],
    ['POST', '/v1/auth/login',    'AuthController@login'],
    ['POST', '/v1/auth/password-code',     'AuthController@passwordCode'],
    ['POST', '/v1/auth/reset-password',    'AuthController@resetPassword'],
    ['POST', '/v1/auth/change-password',   'AuthController@changePassword'],
    ['GET',  '/v1/vault',         'VaultController@get'],
    ['POST', '/v1/vault',         'VaultController@push'],
    ['GET',  '/v1/vault/history', 'VaultController@history'],
];

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$uri    = $_SERVER['REQUEST_URI'] ?? '/';

// 请求路径：兼容任意部署子目录（API 一律取 /v1/ 起）；页面路由（/、/source）按完整路径匹配
$path = parse_url($uri, PHP_URL_PATH) ?: '/';
$pos = strpos($path, '/v1/');
if ($pos !== false) {
    $path = substr($path, $pos);
}
$path = rtrim($path, '/') ?: '/';

foreach ($routes as [$routeMethod, $routePath, $handler]) {
    if ($method === $routeMethod && $path === $routePath) {
        [$controller, $action] = explode('@', $handler);
        try {
            $instance = new ('App\\Controllers\\' . $controller)();
            $response = $instance->{$action}(new \App\Core\Request());
        } catch (\Throwable $e) {
            // 全局兜底：数据库连接失败等异常统一返回 500 JSON（不暴露堆栈给客户端）
            error_log('[API 异常] ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => '服务器内部错误'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (is_array($response)) {
            echo json_encode($response, JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
}

http_response_code(404);
echo json_encode(['error' => 'not_found'], JSON_UNESCAPED_UNICODE);
