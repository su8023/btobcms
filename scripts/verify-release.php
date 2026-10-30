<?php

declare(strict_types=1);

/**
 * 发布包完整性校验（构建产物验收用，不参与发布）
 *
 *   php scripts/verify-release.php dist/btobcms-v1.3.6.zip
 *
 * 检查两类：
 *  A. 完整性 —— 该在包里的东西都在（artisan / vendor / 版本号 / 15 个模块 / views / lang / 目录骨架）
 *  B. 排除正确 —— 不该在包里的东西都不在（.env / .git / node_modules / tests / 用户数据 / 缓存）
 */

$root = str_replace('\\', '/', dirname(__DIR__));
$zipPath = $argv[1] ?? null;
if ($zipPath === null) {
    // 默认取 dist 下最新的 btobcms-*.zip（按修改时间）
    $candidates = glob($root.'/dist/btobcms-*.zip') ?: [];
    usort($candidates, fn ($a, $b) => filemtime($b) <=> filemtime($a));
    $zipPath = $candidates[0] ?? null;
}
if (! str_starts_with($zipPath, '/') && ! preg_match('#^[A-Za-z]:/#', $zipPath)) {
    $zipPath = $root.'/'.$zipPath;
}
if (! is_file($zipPath)) {
    fwrite(STDERR, "找不到包：{$zipPath}\n");
    exit(1);
}

$extractDir = sys_get_temp_dir().'/btobcms-verify-'.getmypid();
@mkdir($extractDir, 0775, true);

$zip = new ZipArchive();
if ($zip->open($zipPath) !== true) {
    fwrite(STDERR, "zip 无法打开\n");
    exit(1);
}
$zip->extractTo($extractDir);
$entryCount = $zip->numFiles;
$zip->close();

$results = [];
$add = function (bool $pass, string $label, string $detail = '') use (&$results): void {
    $results[] = ['pass' => $pass, 'label' => $label, 'detail' => $detail];
};

// ---------------------------------------------------------------- A. 完整性
$add(is_file($extractDir.'/artisan'), 'artisan 存在');
$add(is_file($extractDir.'/vendor/autoload.php'), 'vendor/autoload.php 存在');
$add(is_file($extractDir.'/composer.json') && is_file($extractDir.'/composer.lock'), 'composer.json / composer.lock 存在');

$srcVersion = null;
if (preg_match("/'version'\s*=>\s*'([^']+)'/", (string) file_get_contents($root.'/config/version.php'), $m)) {
    $srcVersion = $m[1];
}
$pkgVersion = null;
if (is_file($extractDir.'/config/version.php')
    && preg_match("/'version'\s*=>\s*'([^']+)'/", (string) file_get_contents($extractDir.'/config/version.php'), $m2)) {
    $pkgVersion = $m2[1];
}
$add($pkgVersion !== null && $pkgVersion === $srcVersion, 'config/version.php 版本号与源码一致', "包内={$pkgVersion} 源码={$srcVersion}");

// 15 个模块
$modules = array_values(array_filter(scandir($root.'/Modules') ?: [], fn ($d) => $d !== '.' && $d !== '..'));
$missingModules = [];
foreach ($modules as $m) {
    if (! is_dir($extractDir.'/Modules/'.$m)) {
        $missingModules[] = $m;
    }
}
$add($missingModules === [] && count($modules) === 15, 'Modules/ 下全部模块目录都在（15 个）',
    '模块数='.count($modules).($missingModules ? ' 缺失='.implode(',', $missingModules) : ''));

// 模块结构抽查：每个模块应有 app/ 目录
$badModules = [];
foreach ($modules as $m) {
    if (! is_dir($extractDir.'/Modules/'.$m.'/app')) {
        $badModules[] = $m;
    }
}
$add($badModules === [], '每个模块的 app/ 目录都在', $badModules ? '缺失='.implode(',', $badModules) : '');

// resources / lang / themes / public / routes / database
$add(is_dir($extractDir.'/resources/views') && count(glob($extractDir.'/resources/views/*') ?: []) > 0, 'resources/views 存在且非空');
$add(is_dir($extractDir.'/lang') && count(glob($extractDir.'/lang/*') ?: []) > 0, 'lang 存在且非空',
    '语种目录='.count(glob($extractDir.'/lang/*') ?: []));
$add(is_dir($extractDir.'/themes'), 'themes/ 存在');
$add(is_file($extractDir.'/public/index.php'), 'public/index.php 存在');
$add(is_dir($extractDir.'/routes') && is_file($extractDir.'/routes/web.php'), 'routes/ 存在');
$add(is_dir($extractDir.'/database/migrations'), 'database/migrations 存在');
$add(is_file($extractDir.'/app/Support/helpers.php'), 'app/Support/helpers.php 存在（composer autoload files）');

// storage / bootstrap-cache 骨架
$skeletonDirs = [
    'storage/app', 'storage/app/public', 'storage/app/private',
    'storage/framework/cache', 'storage/framework/cache/data',
    'storage/framework/sessions', 'storage/framework/views', 'storage/framework/testing',
    'storage/logs', 'bootstrap/cache',
];
$missingSkeleton = [];
foreach ($skeletonDirs as $d) {
    if (! is_dir($extractDir.'/'.$d)) {
        $missingSkeleton[] = $d;
    }
}
$add($missingSkeleton === [], 'storage/ 与 bootstrap/cache 目录骨架齐全', $missingSkeleton ? '缺失='.implode(',', $missingSkeleton) : '');

$missingPlaceholder = [];
foreach ($skeletonDirs as $d) {
    if (! is_file($extractDir.'/'.$d.'/.gitignore')) {
        $missingPlaceholder[] = $d.'/.gitignore';
    }
}
$add($missingPlaceholder === [], '骨架目录内含 .gitignore 占位', $missingPlaceholder ? '缺失='.implode(',', $missingPlaceholder) : '');

// ---------------------------------------------------------------- B. 排除清单
$all = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($extractDir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY,
);
foreach ($it as $f) {
    /** @var SplFileInfo $f */
    $all[] = substr(str_replace('\\', '/', (string) $f), strlen($extractDir.'/'));
}

$hits = [];
$scan = function (callable $test, string $label, bool $projectOnly = true) use (&$hits, $all): void {
    // vendor/ 内部是第三方包的原始内容（如 nwidart 的 stubs/tests 模板），不按项目排除清单判定
    $pool = $projectOnly
        ? array_values(array_filter($all, fn ($p) => ! str_starts_with($p, 'vendor/')))
        : $all;
    $found = array_values(array_filter($pool, $test));
    if ($found) {
        $hits[$label] = $found;
    }
};

$scan(fn ($p) => basename($p) === '.env', '.env 文件');
$scan(fn ($p) => preg_match('#(^|/)\.env\.(?!example$)#', $p) === 1, '.env.* 文件（.env.example 除外）');
$scan(fn ($p) => $p === '.git' || str_starts_with($p, '.git/') || str_contains($p, '/.git/'), '.git 目录');
$scan(fn ($p) => preg_match('#(^|/)node_modules(/|$)#', $p) === 1, 'node_modules 目录');
$scan(fn ($p) => preg_match('#(^|/)tests(/|$)#', $p) === 1, 'tests 目录');
$scan(fn ($p) => preg_match('#^storage/app/.+#', $p) === 1 && basename($p) !== '.gitignore', 'storage/app 下的用户数据');
$scan(fn ($p) => preg_match('#^storage/(framework|logs)/.+#', $p) === 1 && basename($p) !== '.gitignore', 'storage/framework|logs 下的运行时文件');
$scan(fn ($p) => preg_match('#^bootstrap/cache/.+#', $p) === 1 && basename($p) !== '.gitignore', 'bootstrap/cache 下的 .php 缓存');
$scan(fn ($p) => preg_match('#\.sqlite3?$#i', $p) === 1 || preg_match('#\.db$#i', $p) === 1, '数据库文件');
$scan(fn ($p) => preg_match('#\.key$#i', $p) === 1, 'APP_KEY 文件');
$scan(fn ($p) => preg_match('#\.log$#i', $p) === 1, '日志文件');
$scan(fn ($p) => $p === 'public/storage' || str_starts_with($p, 'public/storage/'), 'public/storage 符号链接');
$scan(fn ($p) => str_contains($p, '/.git/'), 'vendor 内的 .git 目录', false);

$add($hits === [], '排除清单：包内无 .env / .git / node_modules / tests / 用户数据 / 缓存 / 数据库',
    $hits === [] ? '' : implode('；', array_map(fn ($k, $v) => $k.' → '.implode(', ', array_slice($v, 0, 5)).(count($v) > 5 ? ' …共'.count($v).'项' : ''), array_keys($hits), $hits)));

// dev 依赖不应进入包
$devPackages = ['phpunit/phpunit', 'mockery/mockery', 'fakerphp/faker', 'nunomaduro/collision', 'laravel/pint', 'laravel/pail', 'laravel/pao'];
$devFound = [];
foreach ($devPackages as $pkg) {
    if (is_dir($extractDir.'/vendor/'.$pkg)) {
        $devFound[] = $pkg;
    }
}
$add($devFound === [], 'vendor/ 无 dev 依赖', $devFound ? '存在='.implode(',', $devFound) : '');

// ---------------------------------------------------------------- C. 可启动性
$php = PHP_BINARY;
$out = [];
$code = 0;
exec('cd '.escapeshellarg($extractDir).' && '.escapeshellarg($php).' artisan --version 2>&1', $out, $code);
$versionLine = trim((string) end($out));
$add($code === 0 && str_contains($versionLine, 'Laravel'), 'artisan 可在解包目录直接启动（vendor 自洽）', $versionLine);

// ---------------------------------------------------------------- 输出
$pass = 0;
$fail = 0;
echo "包：{$zipPath}\n";
echo "条目：{$entryCount}\n\n";
foreach ($results as $r) {
    if ($r['pass']) {
        $pass++;
        echo "  [PASS] {$r['label']}".($r['detail'] !== '' ? "  ({$r['detail']})" : '')."\n";
    } else {
        $fail++;
        echo "  [FAIL] {$r['label']}".($r['detail'] !== '' ? "  ({$r['detail']})" : '')."\n";
    }
}
echo "\n通过 {$pass} 项，失败 {$fail} 项\n";

// 清理
$items = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($extractDir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST,
);
foreach ($items as $item) {
    $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
}
@rmdir($extractDir);

exit($fail === 0 ? 0 : 1);
