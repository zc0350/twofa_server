<?php
/**
 * PHP 内置开发服务器路由脚本：
 *   cd server && php -S 0.0.0.0:8000 router.php
 *
 * 路由优先级：
 *  1. /v1/* → public/index.php（JSON API）
 *  2. /downloads/* → public/downloads/ 下的静态文件（APK）
 *  3. 其余（/、/source 等）→ public/index.php（前端控制器：宣传页 / 源码打包 / API）
 *
 * 生产部署：将 nginx/Apache 文档根指向 public/，无需本脚本；
 * 静态资源（downloads/、favicon.png）与动态入口（index.php）均在该目录内。
 */
declare(strict_types=1);

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$path = rtrim($uri, '/') ?: '/';
$publicRoot = __DIR__ . '/public';

// 1. API 与页面（含 /source 源码打包）→ 项目控制器
if ((strpos($path, '/v1/') === 0) || $path === '/v1' || $path === '/' || $path === '/source') {
    require $publicRoot . '/index.php';
    return;
}

// 2. 静态文件：downloads/*（APK）、根级资源（favicon.png 等）
$rel = ltrim($path, '/');
$file = $publicRoot . '/' . $rel;
if (is_file($file)) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $mimeMap = [
        'apk' => 'application/vnd.android.package-archive',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp',
        'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
        'css' => 'text/css', 'js' => 'application/javascript',
        'zip' => 'application/zip',
    ];
    header('Content-Type: ' . ($mimeMap[$ext] ?? 'application/octet-stream'));
    header('Content-Length: ' . (string) filesize($file));
    header('Cache-Control: public, max-age=3600');
    readfile($file);
    return;
}

// 3. downloads/ 目录请求（无具体文件）→ 提示
if ((strpos($path, '/downloads') === 0)) {
    http_response_code(404);
    echo 'Not Found';
    return;
}

// 4. 未知路径也交给控制器（渲染页面或如实 404）
require $publicRoot . '/index.php';