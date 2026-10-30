<?php

declare(strict_types=1);

/**
 * 发布包构建脚本（WordPress 式自包含包 / SelfUpdater v2）
 *
 *   php scripts/build-release.php [--composer="composer"] [--keep-staging] [--out="dist"]
 *
 * 产出 dist/btobcms-{version}.zip —— 含全部代码 + vendor/（生产依赖），
 * 排除运行时数据（.env / storage 内容 / bootstrap 缓存 / 数据库文件 / .git / 测试 / node_modules）。
 *
 * 服务器上无需 git 与 composer：SelfUpdater 下载该 zip、解压、按保留清单覆盖即完成更新。
 *
 * 资产名约定：btobcms-{tag}.zip（SelfUpdater::download() 按 'btobcms-'.$tag.'.zip' 查找）
 * ⇒ config/version.php 的 version 必须与 git tag 完全一致（例：v1.3.7 → btobcms-v1.3.7.zip）。
 *
 * 约束：
 *  - 绝不把 .env / storage 用户数据 / 数据库文件打进包里（覆盖会毁掉线上数据）；
 *  - 绝不打包 dev 依赖（composer install --no-dev）；
 *  - 绝不跑 composer scripts（package:discover / filament:upgrade 需要线上 .env 与数据库，
 *    在 staging 里跑必然失败；bootstrap/cache 又本就不该进包，服务器首次请求会自行重建）。
 */

$root = str_replace('\\', '/', dirname(__DIR__));

// ---------------------------------------------------------------- 参数解析
$composer = 'composer';
$keepStaging = false;
$outDirName = 'dist';

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--composer=')) {
        $composer = substr($arg, strlen('--composer='));
    } elseif ($arg === '--keep-staging') {
        $keepStaging = true;
    } elseif (str_starts_with($arg, '--out=')) {
        $outDirName = trim(substr($arg, strlen('--out=')), '/');
    } else {
        fwrite(STDERR, "未知参数：{$arg}\n");
        exit(2);
    }
}

function fail(string $message): never
{
    fwrite(STDERR, "✗ {$message}\n");
    exit(1);
}

// ---------------------------------------------------------------- 版本号
$versionFile = $root.'/config/version.php';
if (! is_file($versionFile)) {
    fail('找不到 config/version.php');
}
$versionSource = (string) file_get_contents($versionFile);
$version = null;
if (preg_match("/'version'\s*=>\s*'([^']+)'/", $versionSource, $m)) {
    $version = $m[1];
} elseif (preg_match('/"version"\s*=>\s*"([^"]+)"/', $versionSource, $m)) {
    $version = $m[1];
}
if ($version === null || $version === '') {
    fail('无法从 config/version.php 解析版本号');
}
if (! preg_match('/^v?\d+(\.\d+)*$/', $version)) {
    fail("版本号格式异常：{$version}（应为 v1.3.7 这类形式）");
}

$distDir = $root.'/'.$outDirName;
$stagingDir = $distDir.'/staging';
$zipPath = $distDir.'/btobcms-'.$version.'.zip';

// ---------------------------------------------------------------- 工具函数
/** 纯 PHP 递归删除（不依赖 rm / rmdir，可移植到 Windows 服务器与 CI） */
function rrmdir(string $dir): void
{
    if (! is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
        /** @var SplFileInfo $item */
        if ($item->isDir() && ! $item->isLink()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }
    @rmdir($dir);
}

/** 目录体积（字节） */
function dirSize(string $dir): int
{
    if (! is_dir($dir)) {
        return 0;
    }
    $total = 0;
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY,
    );
    foreach ($items as $item) {
        /** @var SplFileInfo $item */
        if ($item->isFile()) {
            $total += $item->getSize();
        }
    }

    return $total;
}

function humanSize(int $bytes): string
{
    return $bytes >= 1048576
        ? round($bytes / 1048576, 1).' MB'
        : round($bytes / 1024, 1).' KB';
}

// ---------------------------------------------------------------- 排除规则
/** 任意层级命中即跳过的目录名 */
$skipDirNames = [
    '.git', '.github', '.zcode', '.idea', '.vscode', '.codex', '.cursor', '.zed',
    'node_modules', 'tests', 'dist', '.phpunit.cache', 'coverage',
];

/** 整棵跳过的路径前缀（相对项目根，带尾斜杠） */
$skipPathPrefixes = [
    'storage/',
    'bootstrap/cache/',
];

/** 精确排除的文件名 */
$skipFileNames = [
    '.env', '.env.backup', '.env.production', '.env.local', '.env.staging',
    '.user.ini', '.DS_Store', 'Thumbs.db', '.phpunit.result.cache',
];

/** 扩展名/通配排除 */
$skipFilePatterns = [
    '*.sqlite', '*.sqlite3', '*.db', '*.zip', '*.log', '*.key', '*.bak',
];

/**
 * 判断某个相对路径（正斜杠）是否应被排除。
 */
$shouldSkip = function (string $relative) use (
    $skipDirNames, $skipPathPrefixes, $skipFileNames, $skipFilePatterns, $outDirName
): bool {
    foreach ($skipPathPrefixes as $prefix) {
        if (str_starts_with($relative, $prefix)) {
            return true;
        }
    }
    // 输出目录自身（避免把 staging 拷进自己）
    if ($relative === $outDirName.'/' || str_starts_with($relative, $outDirName.'/')) {
        return true;
    }

    $parts = explode('/', $relative);
    $basename = $parts[count($parts) - 1];

    // 目录段黑名单（除最后一段外；最后一段是文件名）
    for ($i = 0; $i < count($parts) - 1; $i++) {
        if (in_array($parts[$i], $skipDirNames, true)) {
            return true;
        }
        // 顶层 vendor 由 composer 重建；public/vendor 是 Livewire 运行期资源，必须保留
        if ($parts[$i] === 'vendor' && ! ($parts[0] === 'public' && $i === 1)) {
            return true;
        }
    }

    if (in_array($basename, $skipFileNames, true)) {
        return true;
    }
    if (preg_match('/^\.env\./', $basename) && $basename !== '.env.example') {
        return true;
    }
    $lower = strtolower($basename);
    foreach ($skipFilePatterns as $pattern) {
        if (fnmatch($pattern, $lower)) {
            return true;
        }
    }

    return false;
};

// ---------------------------------------------------------------- ① 复制代码到 staging
rrmdir($stagingDir);
if (! is_dir($stagingDir) && ! mkdir($stagingDir, 0775, true) && ! is_dir($stagingDir)) {
    fail('无法创建 staging 目录：'.$stagingDir);
}

echo "① 复制代码到 staging（排除运行时数据 / 测试 / dev 依赖）…\n";

$copiedFiles = 0;
$skippedFiles = 0;

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS),
    RecursiveIteratorIterator::LEAVES_ONLY,
);

foreach ($iterator as $file) {
    /** @var SplFileInfo $file */
    $path = str_replace('\\', '/', (string) $file);

    // 顶层 vendor 不复制：交给 composer install --no-dev 重建（干净、无 dev 依赖）
    if ($path === $root.'/vendor' || str_starts_with($path, $root.'/vendor/')) {
        continue;
    }
    // 符号链接不打包（public/storage 指向本机绝对路径，打进包必然失效）
    if ($file->isLink()) {
        $skippedFiles++;

        continue;
    }
    if (! $file->isFile()) {
        continue;
    }

    $relative = substr($path, strlen($root.'/'));

    if ($shouldSkip($relative)) {
        $skippedFiles++;

        continue;
    }

    $target = $stagingDir.'/'.$relative;
    $targetDir = dirname($target);
    if (! is_dir($targetDir) && ! mkdir($targetDir, 0775, true) && ! is_dir($targetDir)) {
        fail("无法创建目录：{$targetDir}");
    }
    if (! @copy($path, $target)) {
        fail("复制失败：{$relative}（文件名过长或权限不足？）");
    }
    $copiedFiles++;
}

// storage / bootstrap-cache 目录骨架（含 .gitignore 占位，保证目录存在且不被 git 追踪）
$dirSkeleton = [
    'storage/app', 'storage/app/public', 'storage/app/private',
    'storage/framework/cache', 'storage/framework/cache/data',
    'storage/framework/sessions', 'storage/framework/views', 'storage/framework/testing',
    'storage/logs', 'bootstrap/cache',
];
foreach ($dirSkeleton as $dir) {
    $target = $stagingDir.'/'.$dir;
    if (! is_dir($target) && ! mkdir($target, 0775, true) && ! is_dir($target)) {
        fail("无法创建骨架目录：{$target}");
    }
    // Laravel 标准占位内容：忽略目录下所有内容，仅保留 .gitignore 自身
    @file_put_contents($target.'/.gitignore', "*\n!.gitignore\n");
}

echo "   复制 {$copiedFiles} 个文件，跳过 {$skippedFiles} 个（排除清单命中）\n";

// ---------------------------------------------------------------- ② staging 内安装生产依赖
echo "② composer install --no-dev（staging 内）…\n";

// composer 可执行形式：*.phar 用当前 PHP 解释器跑；其余当作可执行命令
if (str_ends_with(strtolower($composer), '.phar')) {
    $composerCmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($composer);
} elseif (str_contains($composer, ' ')) {
    $composerCmd = $composer; // 调用方自带完整命令（含解释器）
} else {
    $composerCmd = escapeshellarg($composer);
}

$composerArgs = ' install --no-dev --optimize-autoloader --no-scripts --no-interaction --no-progress --prefer-dist 2>&1';

$previousCwd = getcwd();
if (! chdir($stagingDir)) {
    fail('无法进入 staging 目录：'.$stagingDir);
}
$output = [];
$exitCode = 0;
exec($composerCmd.$composerArgs, $output, $exitCode);
chdir((string) $previousCwd);

if ($exitCode !== 0) {
    echo implode("\n", array_slice($output, -25))."\n";
    fail("composer install 失败（exit {$exitCode}）");
}
echo '   '.trim((string) end($output))."\n";

// 自检：关键文件必须存在
foreach (['artisan', 'vendor/autoload.php', 'composer.json', 'config/version.php'] as $required) {
    if (! is_file($stagingDir.'/'.$required)) {
        fail("构建结果缺少必需文件：{$required}");
    }
}

// ---------------------------------------------------------------- ③ 打 zip
echo "③ 打包 zip…\n";
if (is_file($zipPath) && ! @unlink($zipPath)) {
    fail('无法删除旧包：'.$zipPath);
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fail('无法创建 zip：'.$zipPath);
}

$zipFiles = [];
$zipIterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($stagingDir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY,
);
foreach ($zipIterator as $file) {
    /** @var SplFileInfo $file */
    if (! $file->isFile()) {
        continue;
    }
    $path = str_replace('\\', '/', (string) $file);
    $zipFiles[$path] = substr($path, strlen($stagingDir.'/'));
}
ksort($zipFiles); // 稳定排序，保证包内容顺序可复现
foreach ($zipFiles as $path => $relative) {
    if (! $zip->addFile($path, $relative)) {
        fail('写入 zip 条目失败：'.$relative);
    }
}
$zip->close();

// ---------------------------------------------------------------- ④ 报告
$zipSize = (int) filesize($zipPath);
$vendorSize = dirSize($stagingDir.'/vendor');
$totalSize = dirSize($stagingDir);

echo "\n";
echo "── 构建结果 ────────────────────────────────────────\n";
echo '包路径　　：'.$zipPath."\n";
echo '包体积　　：'.humanSize($zipSize)."（".number_format($zipSize)." 字节）\n";
echo '条目数　　：'.count($zipFiles)." 个文件\n";
echo "解包后总计：".humanSize($totalSize)."\n";
echo 'vendor 占比：'.humanSize($vendorSize).'（占解包体积 '
    .($totalSize > 0 ? round($vendorSize / $totalSize * 100, 1) : 0)."%）\n";
echo "资产名约定：btobcms-{$version}.zip（SelfUpdater 按此名查找）\n";
echo "───────────────────────────────────────────────────\n";

if ($keepStaging) {
    echo "staging 保留在：{$stagingDir}\n";
} else {
    rrmdir($stagingDir);
}

echo "\n上传到 GitHub Release：\n";
echo "  gh release upload {$version} ".escapeshellarg($zipPath)." --clobber\n";
echo "（或用 GitHub API 把该文件作为 Release 资产上传，文件名必须保持 btobcms-{$version}.zip）\n";
