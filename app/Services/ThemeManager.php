<?php

namespace App\Services;

use Illuminate\Support\Facades\Setting as SettingFacade;
use App\Models\Setting;
use Illuminate\Support\Facades\File;
use Throwable;
use ZipArchive;

/**
 * 主题管理器：扫描/启用/安装（ZIP 上传）/删除主题，并把激活主题的
 * 视图目录前置到 View Finder（同名视图即覆盖核心模板）。
 *
 * 主题目录约定：themes/{slug}/
 *   theme.json   清单（name/slug/version/author/description，slug 匹配 /^[a-z][a-z0-9-]*$/）
 *   views/       可选——覆盖任意核心视图（如 layouts/app.blade.php、home.blade.php）
 *   assets/      可选——激活时复制到 public/themes/{slug}/ 供 Web 访问（css/js/图片）
 */
class ThemeManager
{
    public const DEFAULT_THEME = 'default';

    public static function themesPath(): string
    {
        return base_path('themes');
    }

    /**
     * 把激活主题的 views/ 目录前置到 View Finder（幂等：按 config 重建路径表）。
     * 由 web 中间件在每个请求调用——中途切换主题（测试/Octane）也能立即生效。
     *
     * 说明：FileViewFinder 未提供 removeLocation，因此用反射重建 $paths
     * 并清空已解析视图缓存；Filament 等命名空间 hints 不受影响。
     */
    public static function apply(): void
    {
        $finder = view()->getFinder();
        $themePath = self::viewsPath();

        $paths = $themePath !== null
            ? [$themePath, ...config('view.paths')]
            : config('view.paths');

        $propPaths = new \ReflectionProperty($finder, 'paths');
        $propPaths->setValue($finder, $paths);

        // 清空"视图名 → 已解析路径"缓存，避免同名视图停留在上一个主题
        $propViews = new \ReflectionProperty($finder, 'views');
        $propViews->setValue($finder, []);
    }

    /**
     * 全部主题（slug => 清单 + 激活状态）。
     *
     * @return array<string, array{name: string, slug: string, version: string, author: string, description: string, active: bool, has_views: bool, has_assets: bool}>
     */
    public static function all(): array
    {
        $active = self::active();
        $themes = [];

        foreach (glob(self::themesPath().'/*/theme.json') ?: [] as $manifestPath) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true);

            if (! is_array($manifest) || blank($manifest['slug'] ?? null)) {
                continue;
            }

            $slug = (string) $manifest['slug'];
            $dir = dirname($manifestPath);

            $themes[$slug] = [
                'name' => (string) ($manifest['name'] ?? $slug),
                'slug' => $slug,
                'version' => (string) ($manifest['version'] ?? '—'),
                'author' => (string) ($manifest['author'] ?? ''),
                'description' => (string) ($manifest['description'] ?? ''),
                'color' => (string) ($manifest['color'] ?? '#0d4a8f'),
                'color_dark' => (string) ($manifest['color_dark'] ?? '#062a52'),
                'active' => $slug === $active,
                'has_views' => is_dir($dir.'/views'),
                'has_assets' => is_dir($dir.'/assets'),
            ];
        }

        // 激活主题排最前
        uasort($themes, fn ($a, $b) => ($b['active'] <=> $a['active']) ?: strcmp($a['name'], $b['name']));

        return $themes;
    }

    public static function active(): string
    {
        try {
            $slug = Setting::get('site.active_theme', self::DEFAULT_THEME);
        } catch (Throwable) {
            return self::DEFAULT_THEME;
        }

        $slug = is_string($slug) ? $slug : self::DEFAULT_THEME;

        // 主题目录不存在时回落默认
        return is_dir(self::themesPath().'/'.$slug) ? $slug : self::DEFAULT_THEME;
    }

    public static function exists(string $slug): bool
    {
        return is_file(self::themesPath().'/'.$slug.'/theme.json');
    }

    /**
     * 激活主题：发布其静态资源并写入设置。视图覆盖在 AppServiceProvider::boot 中生效。
     */
    public static function activate(string $slug): array
    {
        if (! self::exists($slug)) {
            return ['ok' => false, 'error' => "主题 {$slug} 不存在"];
        }

        self::publishAssets($slug);
        Setting::put('site.active_theme', $slug);

        return ['ok' => true, 'error' => null];
    }

    /**
     * 安装主题 ZIP 包（安全校验 → 解压 → 发布资源）。
     *
     * @return array{ok: bool, slug?: string, name?: string, error?: string, steps?: string[]}
     */
    public static function install(string $zipPath): array
    {
        $steps = [];

        if (! is_file($zipPath)) {
            return ['ok' => false, 'error' => 'ZIP 文件不存在', 'steps' => $steps];
        }

        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            return ['ok' => false, 'error' => '无法打开 ZIP 文件', 'steps' => $steps];
        }

        // 1. 安全校验：拒绝路径穿越 / 绝对路径 / 盘符 / NUL
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string) $zip->getNameIndex($i);
            $normalized = str_replace('\\', '/', $entry);

            if (self::isUnsafeEntry($normalized)) {
                $zip->close();

                return ['ok' => false, 'error' => "ZIP 包含不安全的路径条目：{$entry}", 'steps' => $steps];
            }
        }

        // 2. 定位 theme.json（根级或唯一一级子目录）
        $manifestEntry = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string) $zip->getNameIndex($i);
            $normalized = str_replace('\\', '/', $entry);

            if (str_ends_with($normalized, 'theme.json') && substr_count(trim($normalized, '/'), '/') <= 1) {
                $manifestEntry = $entry;
                break;
            }
        }

        if ($manifestEntry === null) {
            $zip->close();

            return ['ok' => false, 'error' => 'ZIP 中未找到 theme.json（应在包根或唯一一级子目录内）', 'steps' => $steps];
        }

        $manifest = json_decode((string) $zip->getFromName($manifestEntry), true);
        $slug = (string) ($manifest['slug'] ?? '');

        if (! preg_match('/^[a-z][a-z0-9-]*$/', $slug)) {
            $zip->close();

            return ['ok' => false, 'error' => "theme.json 的 slug 非法（须匹配 /^[a-z][a-z0-9-]*$/）：{$slug}", 'steps' => $steps];
        }

        $prefix = rtrim(str_replace('\\', '/', dirname($manifestEntry)), './');
        $target = self::themesPath().'/'.$slug;

        if (is_dir($target)) {
            $zip->close();

            return ['ok' => false, 'error' => "主题 {$slug} 已存在，如需更新请先删除旧目录", 'steps' => $steps];
        }

        // 3. 解压（剥离公共根目录；逐条校验目标路径仍在主题目录内）
        if (! @mkdir($target, 0755, true)) {
            $zip->close();

            return ['ok' => false, 'error' => "无法创建目录：{$target}", 'steps' => $steps];
        }

        $realTarget = realpath($target);
        $extracted = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string) $zip->getNameIndex($i);
            $relative = ltrim(str_replace('\\', '/', substr($entry, strlen($prefix) > 0 ? strlen($prefix) + 1 : 0)), '/');

            if ($relative === '' || str_ends_with($relative, '/')) {
                continue;
            }

            if (str_starts_with(basename($normalized = $relative), '.')) {
                continue;
            }

            // 剥离公共根目录后必须再校验一次：
            // 前置校验针对原始条目名，而 $relative 是剥离前缀后的结果，
            // 两者可能不同（恶意包可把穿越段放在前缀之后）。
            if (self::isUnsafeEntry($relative)) {
                $zip->close();
                self::cleanupDir($target);

                return ['ok' => false, 'error' => "ZIP 条目路径非法：{$entry}", 'steps' => $steps];
            }

            $dest = $target.'/'.$relative;

            // 先建父目录再校验：目录不存在时 realpath 返回 false 会被误判越界
            // （已存在时不要调 mkdir —— PHP 对已存在目录仍会发 "File exists" 警告）
            $destDir = dirname($dest);

            if (! is_dir($destDir)) {
                @mkdir($destDir, 0755, true);
            }

            // 边界校验必须带分隔符：
            // 只比较前缀的话，themes/foobar 会通过 themes/foo 的检查（越界到兄弟目录）
            $realDir = realpath($destDir);

            if ($realDir === false
                || ($realDir !== $realTarget && ! str_starts_with($realDir, $realTarget.DIRECTORY_SEPARATOR))) {
                $zip->close();
                self::cleanupDir($target);

                return ['ok' => false, 'error' => "解压条目越界：{$entry}", 'steps' => $steps];
            }

            $stream = $zip->getStreamIndex($i);

            if ($stream !== false) {
                file_put_contents($dest, stream_get_contents($stream));
                $extracted++;
            }
        }

        $zip->close();

        $steps[] = "已解压 {$extracted} 个文件到 themes/{$slug}";

        if (! self::exists($slug)) {
            return ['ok' => false, 'error' => '解压后未找到 theme.json，已清理', 'steps' => $steps];
        }

        $steps[] = 'theme.json 校验通过：'.($manifest['name'] ?? $slug).' v'.($manifest['version'] ?? '—');
        self::publishAssets($slug);
        $steps[] = '静态资源已发布到 public/themes/'.$slug;

        return [
            'ok' => true,
            'slug' => $slug,
            'name' => (string) ($manifest['name'] ?? $slug),
            'steps' => $steps,
        ];
    }

    /**
     * 删除主题（激活中的主题拒绝删除）。
     */
    public static function delete(string $slug): array
    {
        if ($slug === self::active()) {
            return ['ok' => false, 'error' => '主题正在使用中，请先启用其他主题'];
        }

        if (! self::exists($slug)) {
            return ['ok' => false, 'error' => "主题 {$slug} 不存在"];
        }

        File::deleteDirectory(self::themesPath().'/'.$slug);
        File::deleteDirectory(public_path('themes/'.$slug));

        return ['ok' => true, 'error' => null];
    }

    /**
     * 主题视图目录（存在时由 AppServiceProvider 前置到 View Finder）。
     */
    public static function viewsPath(?string $slug = null): ?string
    {
        $slug ??= self::active();
        $path = self::themesPath().'/'.$slug.'/views';

        return $slug !== self::DEFAULT_THEME && is_dir($path) ? $path : null;
    }

    /**
     * 已发布主题资源（public/themes/{slug}）的 Web 访问前缀，不存在返回 null。
     */
    public static function assetsUrl(?string $slug = null): ?string
    {
        $slug ??= self::active();

        return $slug !== self::DEFAULT_THEME && is_dir(public_path('themes/'.$slug))
            ? '/themes/'.$slug
            : null;
    }

    /**
     * 把主题 assets/ 复制到 public/themes/{slug}/（Web 可访问）。
     */
    public static function publishAssets(string $slug): void
    {
        $source = self::themesPath().'/'.$slug.'/assets';

        if (! is_dir($source)) {
            return;
        }

        File::ensureDirectoryExists(public_path('themes/'.$slug));
        File::copyDirectory($source, public_path('themes/'.$slug));
    }

    /**
     * ZIP 条目名是否不安全。
     *
     * 判定要点：
     *   - 路径段恰好等于 '..'（不只是 '../'）——  '..'、'foo/..' 同样能向上跳；
     *   - 段内含 ':'（Windows 盘符，含内嵌形式 mid/C:/x）；
     *   - UNC 路径（//server/share）与绝对路径（/etc/passwd）；
     *   - NUL 与控制字符。
     */
    protected static function isUnsafeEntry(string $normalized): bool
    {
        if ($normalized === '') {
            return false;
        }

        if (preg_match('/[\x00-\x1F]/', $normalized) === 1) {
            return true;
        }

        if (str_starts_with($normalized, '/') || str_starts_with($normalized, '//')) {
            return true;
        }

        if (preg_match('#^[A-Za-z]:#', $normalized) === 1) {
            return true;
        }

        foreach (explode('/', $normalized) as $segment) {
            if ($segment === '..') {
                return true;
            }

            if ($segment !== '' && str_contains($segment, ':')) {
                return true;
            }
        }

        return false;
    }

    /**
     * 安装失败时清掉已建目录，避免留下半成品主题。
     */
    protected static function cleanupDir(string $dir): void
    {
        try {
            if (is_dir($dir)) {
                File::deleteDirectory($dir);
            }
        } catch (Throwable) {
        }
    }
}
