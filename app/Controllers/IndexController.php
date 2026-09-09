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
            'sourceUrl'   => "https://github.com/zc0350/twofa_server",
            'genAt'       => date('Y-m-d H:i'),
        ];
        extract($vars, EXTR_SKIP); // 模板内直接以裸变量使用
        require SERVER_ROOT . '/app/Views/home.php';
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
}