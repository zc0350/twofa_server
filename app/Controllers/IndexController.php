<?php
declare(strict_types=1);

namespace App\Controllers;

/**
 * 首页控制器：项目宣传落地页 + 产出物下载（APK / 服务端源码）。
 * 页面不依赖数据库，纯静态资源；链接为相对路径，任意部署子目录可用。
 */
final class IndexController extends Controller
{
    /** @var string APK 静态目录（对外可直接访问） */
    private const DOWNLOADS_DIR = SERVER_ROOT . '/public/downloads';

    /** @var array 源码打包时排除的路径片段（密钥、运行时、大体积产物） */
    private const SOURCE_EXCLUDES = [
        '.git/', 'vendor/', 'runtime/', 'public/downloads',
    ];

    public function index(): void
    {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-cache');

        $downloads = $this->listApks();
        $vars = [
            'downloads'   => $downloads,
            'apkCount'    => count($downloads),
            'sourceUrl'   => $this->url('source'),
            'genAt'       => date('Y-m-d H:i'),
        ];
        extract($vars, EXTR_SKIP); // 模板内直接以裸变量使用
        require SERVER_ROOT . '/app/Views/home.php';
    }

    /** 服务端源码实时打包下载（含说明文件，永不包含 .env 等敏感文件） */
    public function source(): void
    {
        $zipPath = $this->buildSourceZip();
        $name = 'fengxin-2fa-server-source-' . date('Ymd-His') . '.zip';

        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . (string) filesize($zipPath));
        header('Cache-Control: no-store');
        readfile($zipPath);
        @unlink($zipPath); // 一次性下载，不落盘留存
        exit;
    }

    /** @return array<int, array{version:string,href:string,sizeMb:string,date:string}> 按版本倒序 */
    private function listApks(): array
    {
        $items = [];
        foreach ((glob(self::DOWNLOADS_DIR . '/*.apk') ?: []) as $file) {
            $name = basename($file);
            if (preg_match('/[vV]?(\d+\.\d+\.\d+)\.apk$/', $name, $m) !== 1) {
                continue;
            }
            $items[] = [
                'version' => $m[1],
                'href'    => $this->url('downloads/' . rawurlencode($name)),
                'sizeMb'  => number_format(filesize($file) / 1048576, 1) . ' MB',
                'date'    => date('Y-m-d', (int) filemtime($file)),
            ];
        }
        usort($items, static fn (array $a, array $b) => version_compare($b['version'], $a['version']));
        return $items;
    }

    /** 相对部署根目录的 URL（支持根路径与任意子目录部署） */
    private function url(string $path): string
    {
        $base = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
        $base = $base === '/' || $base === '.' ? '' : $base;
        return $base . '/' . $path;
    }

    private function buildSourceZip(): string
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'fxsrc_');
        if ($zipPath === false) {
            $this->fail(500, '源码打包失败');
        }
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::OVERWRITE) !== true) {
            $this->fail(500, '源码打包失败');
        }

        $root = SERVER_ROOT;
        $prefixLen = strlen($root) + 1;
        $count = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if (!$item->isFile()) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($item->getPathname(), $prefixLen));
            if ($this->isExcluded($rel)) {
                continue;
            }
            $zip->addFile($item->getPathname(), 'fengxin-2fa-server/' . $rel);
            $count++;
        }
        $zip->addFromString('fengxin-2fa-server/README.txt', $this->sourceReadmeText());
        $zip->close();

        if ($count === 0) {
            @unlink($zipPath);
            $this->fail(500, '源码打包失败');
        }
        return $zipPath;
    }

    private function isExcluded(string $rel): bool
    {
        if (strncmp(basename($rel), '.env', 4) === 0) {
            return true;
        }
        foreach (self::SOURCE_EXCLUDES as $pattern) {
            if (strpos($rel, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }

    private function sourceReadmeText(): string
    {
        return implode("\n", [
            '风信密码器 · 服务端源代码说明',
            '==============================',
            '',
            '本包为当前部署的服务端源码快照（自动生成），已去除：',
            '  - .env（密钥配置，请勿分发）',
            '  - runtime/（运行缓存）',
            '  - public/downloads/（APK 安装包，请在官网页面下载）',
            '',
            '部署步骤：',
            '  1. PHP 8.1+（pdo_mysql、openssl、zip 扩展）+ MySQL 5.7+/8.0',
            '  2. 导入 sql/init.sql 完成建库建表',
            '  3. 复制 .example.env 为 .env，填写数据库连接与令牌密钥',
            '  4. nginx/Apache 将文档根指向 public/；开发调试可运行：php -S 0.0.0.0:8000 router.php',
            '  5. 自检：GET /v1/vault 应返回 401（未登录）；根路径应返回项目宣传页',
            '',
            '接口一览（JSON API，Bearer 令牌鉴权）：',
            '  POST /v1/auth/register|verify|login|password-code|reset-password|change-password',
            '  GET  /v1/vault  · 读取最新加密快照',
            '  POST /v1/vault  · 乐观并发写入（baseVersion + 版本号单调递增）',
            '  GET  /v1/vault/history · 历史归档（保留最近 10 版或 30 天）',
            '',
            '安全模型：验证码数据客户端 AES-256-GCM 加密后再上传，服务器零知识存储；',
            '历史归档同为密文，管理员无法读取内容。',
        ]);
    }
}