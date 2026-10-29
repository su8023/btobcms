<?php

namespace App\Services;

use FilesystemIterator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

/**
 * 插件上传安装服务。
 *
 * 插件 = Modules/{Name}/ 目录
 *      + 根 composer.json 的 PSR-4 映射（Modules\{Name}\ 等 3 行）
 *      + modules_statuses.json 中的启用状态。
 *
 * 安全红线：
 *  - 逐条校验 zip 条目名：拒绝路径穿越（..）、绝对路径、Windows 盘符 / UNC、NUL 与控制字符；
 *  - 校验先于解压：任一条目非法即整体拒绝，绝不写出一个文件；
 *  - 每个解压目标目录都经 realpath 前缀校验，保证落在 Modules/{Name} 内；
 *  - zip 内代码从不被执行（composer dump-autoload 只扫描类映射，不加载业务类）；
 *  - zip 内的符号链接条目按普通文件写出字节，不会在磁盘创建链接。
 */
class PluginUploader
{
    /** 插件名规则：大写字母开头、仅字母数字（module.json 的 name 必须匹配） */
    public const NAME_PATTERN = '/^[A-Z][A-Za-z0-9]+$/';

    /** composer dump-autoload 超时（秒） */
    protected const AUTOLOAD_TIMEOUT = 120;

    /** composer.phar 回退路径（composer 命令不在 PATH 时使用） */
    protected const COMPOSER_PHAR = 'E:/dev-tools/composer.phar';

    /* ----------------------------------------------------------
     | 上传安装
     * --------------------------------------------------------- */

    /**
     * 上传并安装插件 zip 包。
     *
     * @param  string  $zipPath  zip 文件路径
     * @param  bool  $skipAutoload  true 时不执行 composer dump-autoload（测试或由调用方稍后统一刷新）
     * @return array{ok: bool, name?: string, category?: string|null, version?: string|null, error?: string, steps: array<int, string>}
     */
    public function upload(string $zipPath, bool $skipAutoload = false): array
    {
        $steps = [];

        if (! is_file($zipPath) || ! is_readable($zipPath)) {
            return $this->fail($steps, "zip 文件不存在或不可读：{$zipPath}");
        }

        $zip = new ZipArchive();
        $errorCode = $zip->open($zipPath);

        if ($errorCode !== true) {
            return $this->fail($steps, '无法打开 zip 文件（ZipArchive 错误码 '.$errorCode.'），请确认是有效的 zip 压缩包');
        }

        try {
            // 1. 逐条安全校验（先于任何写出动作）
            $entries = $this->scanEntries($zip, $error);

            if ($entries === null) {
                return $this->fail($steps, (string) $error);
            }
            $steps[] = 'zip 安全校验通过：'.count($entries).' 个文件条目，无路径穿越 / 绝对路径 / 盘符';

            // 2. 定位并校验 module.json（根目录或唯一一级子目录内）
            $manifest = $this->locateManifest($zip, $entries, $error);

            if ($manifest === null) {
                return $this->fail($steps, (string) $error);
            }
            [$manifestData, , $stripPrefix] = $manifest;

            $name = (string) $manifestData['name'];
            $category = $this->manifestString($manifestData, 'category');
            $version = $this->manifestString($manifestData, 'version');

            $meta = array_filter([
                $category !== null ? "类别 {$category}" : null,
                $version !== null ? "版本 {$version}" : null,
            ]);
            $steps[] = '发现插件清单：'.$name.($meta !== [] ? '（'.implode('，', $meta).'）' : '');

            // 3. 已存在检查
            $dest = base_path("Modules/{$name}");

            if (is_dir($dest)) {
                return $this->fail($steps, "插件 {$name} 已存在，如需更新请先在插件中心停用并删除旧目录");
            }

            // 4. 解压（剥离公共根目录；逐条校验目标路径仍在 Modules/{Name} 内；目录自动创建）
            $extracted = $this->extract($zip, $entries, $dest, $stripPrefix, $error);

            if ($extracted === null) {
                $this->removeDirectory($dest);
                return $this->fail($steps, (string) $error);
            }
            $steps[] = "已解压 {$extracted} 个文件到 Modules/{$name}";

            // 5. 解压后校验：module.json 可解析、providers 非空，否则回滚删除
            $onDisk = json_decode((string) @file_get_contents($dest.'/module.json'), true);

            if (! is_array($onDisk)
                || ! isset($onDisk['providers'])
                || ! is_array($onDisk['providers'])
                || $onDisk['providers'] === []) {
                $this->removeDirectory($dest);

                return $this->fail($steps, 'module.json 校验失败：providers 必须是非空数组（已回滚删除已解压目录）');
            }
            $steps[] = '解压后校验通过：module.json 可解析且 providers 非空';

            // 6. 注册 PSR-4 映射（根 composer.json 追加 3 行）
            if (! $this->registerPsr4($name, $error)) {
                $this->removeDirectory($dest);

                return $this->fail($steps, (string) $error);
            }
            $steps[] = "已在根 composer.json 注册 PSR-4 映射：Modules\\\\{$name}\\\\ 等 3 行";

            // 7. 启用：直接读写 modules_statuses.json（新模块在当前进程的 Module facade 中不可见）
            if (! $this->writeStatus($name, true, $error)) {
                $this->unregisterPsr4($name);
                $this->removeDirectory($dest);

                return $this->fail($steps, (string) $error);
            }
            $steps[] = "已在 modules_statuses.json 中启用插件 {$name}";

            // 8. 刷新 autoload
            if ($skipAutoload) {
                $steps[] = '已按参数跳过 composer dump-autoload';
            } else {
                $steps[] = $this->refreshAutoload();
            }

            // 9. 执行迁移：带数据表的插件不跑迁移，首次触达即 SQL 报错
            try {
                Artisan::call('migrate', ['--force' => true]);
                $output = trim(Artisan::output());
                $steps[] = $output !== ''
                    ? '迁移完成：'.mb_substr($output, -120)
                    : '迁移完成（无待执行项）';
            } catch (\Throwable $e) {
                $steps[] = '迁移失败（插件已启用，请 SSH 执行 php artisan migrate）：'.$e->getMessage();
            }

            return ['ok' => true, 'name' => $name, 'category' => $category, 'version' => $version, 'steps' => $steps];
        } finally {
            $zip->close();
        }
    }

    /* ----------------------------------------------------------
     | 删除（仅停用状态）
     * --------------------------------------------------------- */

    /**
     * 删除插件：删除 Modules/{Name} 目录、移除 PSR-4 映射与启用状态，并刷新 autoload。
     *
     * @return array{ok: bool, error?: string, steps: array<int, string>}
     */
    public function delete(string $name): array
    {
        $steps = [];

        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            return $this->fail($steps, "非法插件名：{$name}（必须匹配 ".self::NAME_PATTERN.'）');
        }

        // 1. 仅停用状态下允许删除
        $statuses = $this->readStatuses();

        if (! array_key_exists($name, $statuses)) {
            return $this->fail($steps, "插件 {$name} 未在 modules_statuses.json 中登记，请先在插件中心停用后再删除");
        }

        if ($statuses[$name] !== false) {
            return $this->fail($steps, "插件 {$name} 处于启用状态，请先在插件中心停用后再删除");
        }
        $steps[] = "校验通过：插件 {$name} 处于停用状态";

        // 2. 递归删除目录
        $dest = base_path("Modules/{$name}");

        if (is_dir($dest)) {
            if (! $this->removeDirectory($dest)) {
                return $this->fail($steps, "删除插件目录失败：Modules/{$name}（请检查文件是否被占用或权限不足）");
            }
            $steps[] = "已删除插件目录 Modules/{$name}";
        } else {
            $steps[] = "插件目录 Modules/{$name} 不存在，跳过目录删除";
        }

        // 3. 移除 PSR-4 三行映射
        $steps[] = $this->unregisterPsr4($name)
            ? "已移除根 composer.json 中 {$name} 的 PSR-4 映射"
            : "根 composer.json 中未发现 {$name} 的 PSR-4 映射（或移除失败），请人工确认";

        // 4. 移除启用状态
        $this->removeStatus($name);
        $steps[] = "已从 modules_statuses.json 移除 {$name}";

        // 5. 刷新 autoload
        $steps[] = $this->refreshAutoload();

        return ['ok' => true, 'steps' => $steps];
    }

    /* ----------------------------------------------------------
     | 内部实现
     * --------------------------------------------------------- */

    /**
     * 逐条扫描 zip 条目并做安全校验，返回文件条目列表（目录与打包垃圾已剔除）。
     *
     * @return list<array{name: string, index: int}>|null  失败返回 null 并填充 $error
     */
    private function scanEntries(ZipArchive $zip, ?string &$error = null): ?array
    {
        $files = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);

            if ($stat === false || $stat['name'] === '') {
                continue;
            }

            $raw = (string) $stat['name'];

            if (preg_match('/[\x00-\x1F]/', $raw) === 1) {
                $error = 'zip 条目包含 NUL 或控制字符，已拒绝：'.addcslashes($raw, "\x00..\x1F");

                return null;
            }

            $name = str_replace('\\', '/', $raw);

            // Windows 盘符（C:/...）与 UNC（//server/...）绝对路径
            if (preg_match('#^[A-Za-z]:#', $name) === 1 || str_starts_with($name, '//')) {
                $error = "zip 条目包含 Windows 盘符或 UNC 绝对路径，已拒绝：{$raw}";

                return null;
            }

            // Unix 绝对路径
            if (str_starts_with($name, '/')) {
                $error = "zip 条目包含绝对路径，已拒绝：{$raw}";

                return null;
            }

            // 逐段检查：拒绝 ..、内嵌盘符（如 mid/C:/x）
            foreach (explode('/', $name) as $segment) {
                if ($segment === '..') {
                    $error = "zip 条目包含路径穿越（..），已拒绝：{$raw}";

                    return null;
                }

                if ($segment !== '' && str_contains($segment, ':')) {
                    $error = "zip 条目包含非法盘符路径，已拒绝：{$raw}";

                    return null;
                }
            }

            // macOS 打包垃圾直接忽略
            if (str_starts_with($name, '__MACOSX/') || basename($name) === '.DS_Store') {
                continue;
            }

            // 目录条目（以 / 结尾）不写入，解压时按需 mkdir
            if (str_ends_with($name, '/')) {
                continue;
            }

            $files[] = ['name' => $name, 'index' => $i];
        }

        return $files;
    }

    /**
     * 定位 module.json：优先根目录；否则要求唯一一级子目录内恰好一个。
     *
     * @param  list<array{name: string, index: int}>  $entries
     * @return array{0: array<string, mixed>, 1: int, 2: string}|null  [清单数据, 条目序号, 需剥离的公共根前缀]
     */
    private function locateManifest(ZipArchive $zip, array $entries, ?string &$error = null): ?array
    {
        $rootEntry = null;
        $subEntries = [];

        foreach ($entries as $entry) {
            if ($entry['name'] === 'module.json') {
                $rootEntry = $entry;
            } elseif (preg_match('#^[^/]+/module\.json$#', $entry['name']) === 1) {
                $subEntries[] = $entry;
            }
        }

        $chosen = $rootEntry;
        $stripPrefix = '';

        if ($chosen === null) {
            if (count($subEntries) > 1) {
                $error = 'zip 中存在多个 module.json（'.implode('、', array_column($subEntries, 'name')).'），无法确定插件根目录';

                return null;
            }

            if ($subEntries === []) {
                $error = 'zip 中未找到 module.json：插件包必须在根目录或唯一一级子目录内包含 module.json';

                return null;
            }

            $chosen = $subEntries[0];
            $stripPrefix = explode('/', $chosen['name'])[0].'/';
        }

        $stream = $zip->getStreamIndex($chosen['index']);

        if ($stream === false) {
            $error = '无法读取 zip 条目：'.$chosen['name'];

            return null;
        }

        $contents = stream_get_contents($stream);
        fclose($stream);

        $data = json_decode((string) $contents, true);

        if (! is_array($data)
            || ! isset($data['name'])
            || ! is_string($data['name'])
            || preg_match(self::NAME_PATTERN, $data['name']) !== 1) {
            $error = 'module.json 校验失败：缺少合法的 name 字段（须匹配 '.self::NAME_PATTERN.'）';

            return null;
        }

        return [$data, $chosen['index'], $stripPrefix];
    }

    /**
     * 逐文件解压到 $dest：剥离公共根前缀、自动创建目录、realpath 前缀校验。
     *
     * @param  list<array{name: string, index: int}>  $entries
     * @return int|null  成功返回写出文件数，失败返回 null 并填充 $error
     */
    private function extract(ZipArchive $zip, array $entries, string $dest, string $stripPrefix, ?string &$error = null): ?int
    {
        if (! is_dir($dest) && ! mkdir($dest, 0775, true) && ! is_dir($dest)) {
            $error = "无法创建插件目录：{$dest}";

            return null;
        }

        $destReal = realpath($dest);

        if ($destReal === false) {
            $error = "无法解析插件目录真实路径：{$dest}";

            return null;
        }

        $count = 0;

        foreach ($entries as $entry) {
            $relative = $entry['name'];

            if ($stripPrefix !== '' && str_starts_with($relative, $stripPrefix)) {
                $relative = substr($relative, strlen($stripPrefix));
            }

            if ($relative === '') {
                continue;
            }

            $target = $destReal.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $targetDir = dirname($target);

            if (! is_dir($targetDir) && ! mkdir($targetDir, 0775, true) && ! is_dir($targetDir)) {
                $error = "无法创建目录：{$targetDir}";

                return null;
            }

            // 目标目录必须真实落在 Modules/{Name} 内（安全红线）
            $targetDirReal = realpath($targetDir);

            if ($targetDirReal === false
                || ($targetDirReal !== $destReal && ! str_starts_with($targetDirReal, $destReal.DIRECTORY_SEPARATOR))) {
                $error = "解压目标越界（安全防护拦截）：{$entry['name']}";

                return null;
            }

            $stream = $zip->getStreamIndex($entry['index']);

            if ($stream === false) {
                $error = '无法读取 zip 条目：'.$entry['name'];

                return null;
            }

            $bytes = stream_get_contents($stream);
            fclose($stream);

            if (file_put_contents($target, (string) $bytes) === false) {
                $error = '写入文件失败：'.$relative;

                return null;
            }

            $count++;
        }

        return $count;
    }

    /**
     * 在根 composer.json 的 autoload.psr-4 追加本插件 3 行映射并写回。
     */
    private function registerPsr4(string $name, ?string &$error = null): bool
    {
        $path = base_path('composer.json');
        $data = json_decode((string) file_get_contents($path), true);

        if (! is_array($data)) {
            $error = '根 composer.json 无法解析，无法注册 PSR-4 映射';

            return false;
        }

        $prefix = "Modules\\{$name}\\";
        $data['autoload']['psr-4'] = array_merge(
            (array) ($data['autoload']['psr-4'] ?? []),
            [
                $prefix => "Modules/{$name}/app/",
                $prefix.'Database\\Factories\\' => "Modules/{$name}/database/factories/",
                $prefix.'Database\\Seeders\\' => "Modules/{$name}/database/seeders/",
            ]
        );

        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($encoded === false || file_put_contents($path, $encoded."\n") === false) {
            $error = '根 composer.json 写入失败，无法注册 PSR-4 映射';

            return false;
        }

        return true;
    }

    /**
     * 从根 composer.json 移除本插件 3 行映射；无映射或写失败返回 false。
     */
    private function unregisterPsr4(string $name): bool
    {
        $path = base_path('composer.json');
        $data = json_decode((string) file_get_contents($path), true);

        if (! is_array($data)
            || ! isset($data['autoload']['psr-4'])
            || ! is_array($data['autoload']['psr-4'])) {
            return false;
        }

        $prefix = "Modules\\{$name}\\";
        $removed = false;

        foreach ([$prefix, $prefix.'Database\\Factories\\', $prefix.'Database\\Seeders\\'] as $key) {
            if (array_key_exists($key, $data['autoload']['psr-4'])) {
                unset($data['autoload']['psr-4'][$key]);
                $removed = true;
            }
        }

        if (! $removed) {
            return false;
        }

        $encoded = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return $encoded !== false && file_put_contents($path, $encoded."\n") !== false;
    }

    /**
     * @return array<string, mixed>
     */
    private function readStatuses(): array
    {
        $path = base_path('modules_statuses.json');

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * 直接读写 modules_statuses.json（不依赖 Module facade）。
     */
    private function writeStatus(string $name, bool $enabled, ?string &$error = null): bool
    {
        $statuses = $this->readStatuses();
        $statuses[$name] = $enabled;

        $encoded = json_encode($statuses, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($encoded === false || file_put_contents(base_path('modules_statuses.json'), $encoded."\n") === false) {
            $error = 'modules_statuses.json 写入失败，无法启用插件';

            return false;
        }

        return true;
    }

    private function removeStatus(string $name): void
    {
        $statuses = $this->readStatuses();

        if (! array_key_exists($name, $statuses)) {
            return;
        }

        unset($statuses[$name]);

        $encoded = json_encode($statuses, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($encoded !== false) {
            file_put_contents(base_path('modules_statuses.json'), $encoded."\n");
        }
    }

    /**
     * 刷新 autoload：先尝试 composer 命令，失败回退 PHP_BINARY + composer.phar；
     * 再失败不视为安装失败，仅在步骤日志中提示手动执行。
     */
    private function refreshAutoload(): string
    {
        $result = Process::path(base_path())
            ->timeout(self::AUTOLOAD_TIMEOUT)
            ->run('composer dump-autoload');

        if ($result->successful()) {
            return 'composer dump-autoload 完成（PSR-4 映射已刷新）';
        }

        if (is_file(self::COMPOSER_PHAR)) {
            $fallback = Process::path(base_path())
                ->timeout(self::AUTOLOAD_TIMEOUT)
                ->run([PHP_BINARY, self::COMPOSER_PHAR, 'dump-autoload']);

            if ($fallback->successful()) {
                return 'composer dump-autoload 完成（通过 composer.phar 回退执行）';
            }
        }

        return 'autoload 未刷新：请手动执行 composer dump-autoload';
    }

    /**
     * 递归删除目录；目录不存在视为成功。
     */
    private function removeDirectory(string $dir): bool
    {
        if (! is_dir($dir)) {
            return true;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        return @rmdir($dir);
    }

    private function manifestString(array $manifest, string $key): ?string
    {
        $value = $manifest[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param  array<int, string>  $steps
     * @return array{ok: false, error: string, steps: array<int, string>}
     */
    private function fail(array $steps, string $error): array
    {
        return ['ok' => false, 'error' => $error, 'steps' => $steps];
    }
}
