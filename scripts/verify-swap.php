<?php

declare(strict_types=1);

/**
 * 「假旧站点」覆盖模拟（构建验收用，不参与发布）
 *
 *   php scripts/verify-swap.php dist/btobcms-v1.3.6.zip
 *
 * 直接调用真实的 Modules\UpdateCenter\Services\SelfUpdater::swap()（反射调用 protected 方法），
 * 验证「保留式覆盖」语义：.env / storage 用户数据 / bootstrap 缓存永不被包覆盖。
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

require $root.'/vendor/autoload.php';

$siteDir = sys_get_temp_dir().'/btobcms-fakesite-'.getmypid();
$extractDir = $siteDir.'/storage/app/update/extract';

// ---------------------------------------------------------------- ① 造一个「旧站点」
@mkdir($siteDir, 0775, true);

$zip = new ZipArchive();
if ($zip->open($zipPath) !== true) {
    fwrite(STDERR, "zip 无法打开\n");
    exit(1);
}
$zip->extractTo($siteDir);          // 先铺一份完整应用（= 新版代码）
$zip->close();

// 覆写成「旧站点」的样子
file_put_contents($siteDir.'/config/version.php', "<?php\n\nreturn [\n    'version' => 'v1.3.0',\n];\n");
$oldEnv = "APP_NAME=OldSite\nAPP_KEY=base64:OLDKEY0000000000000000000000000000000000=\nDB_CONNECTION=sqlite\nDB_DATABASE=/var/www/old/data.sqlite\n";
file_put_contents($siteDir.'/.env', $oldEnv);

@mkdir($siteDir.'/storage/app/uploads', 0775, true);
@mkdir($siteDir.'/storage/framework/sessions', 0775, true);
@mkdir($siteDir.'/storage/logs', 0775, true);
file_put_contents($siteDir.'/storage/app/data.sqlite', 'OLD-SQLITE-USER-DATA');
file_put_contents($siteDir.'/storage/app/uploads/photo.png', 'OLD-UPLOADED-PNG');
file_put_contents($siteDir.'/storage/logs/laravel.log', "[2026-01-01] old log line\n");
file_put_contents($siteDir.'/storage/framework/sessions/sess_abc123', 'OLD-SESSION');
file_put_contents($siteDir.'/storage/app/update-state.json', '{"tag":"v1.3.0","step":"done","status":"done"}');

@mkdir($siteDir.'/bootstrap/cache', 0775, true);
file_put_contents($siteDir.'/bootstrap/cache/packages.php', "<?php return ['old-compiled-cache'];\n");
file_put_contents($siteDir.'/bootstrap/cache/services.php', "<?php return ['old-services-cache'];\n");

file_put_contents($siteDir.'/modules_statuses.json', json_encode([
    'AutoSeo' => false,      // 旧站点已手动关闭 AutoSeo
    'LeadLens' => true,
], JSON_PRETTY_PRINT));

// 旧站点里「上个版本有、新版本已删除」的文件
@mkdir($siteDir.'/app/Legacy', 0775, true);
file_put_contents($siteDir.'/app/Legacy/OldRemoved.php', "<?php // 旧版本遗留文件\n");
// 一个会被新包覆盖的文件（做标记）
file_put_contents($siteDir.'/app/Support/helpers.php', "<?php\n// STALE-MARKER-OLD-CODE\n");

// ---------------------------------------------------------------- ② 按 SelfUpdater 的布局解压
@mkdir($extractDir, 0775, true);
$zip = new ZipArchive();
$zip->open($zipPath);
$zip->extractTo($extractDir);
$zip->close();

// ---------------------------------------------------------------- ③ 调用真实 SelfUpdater::swap()
$updater = new Modules\UpdateCenter\Services\SelfUpdater(
    basePath: $siteDir,
    updateDir: $siteDir.'/storage/app/update',
    logPath: $siteDir.'/storage/logs/update-center.log',
);
$method = new ReflectionMethod($updater, 'swap');
$method->setAccessible(true);
$log = [];
$pkgTag = basename($zipPath, '.zip');
$pkgTag = substr($pkgTag, strlen('btobcms-'));
$method->invokeArgs($updater, [$pkgTag, &$log]);

// ---------------------------------------------------------------- ④ 断言
$results = [];
$add = function (bool $pass, string $label, string $detail = '') use (&$results): void {
    $results[] = ['pass' => $pass, 'label' => $label, 'detail' => $detail];
};
$read = fn (string $p): ?string => is_file($p) ? (string) file_get_contents($p) : null;

$add($read($siteDir.'/.env') === $oldEnv, '.env 未被覆盖（内容逐字节一致）',
    $read($siteDir.'/.env') === $oldEnv ? '' : '已被改写');
$add($read($siteDir.'/storage/app/data.sqlite') === 'OLD-SQLITE-USER-DATA', 'storage/app/data.sqlite 未被覆盖');
$add($read($siteDir.'/storage/app/uploads/photo.png') === 'OLD-UPLOADED-PNG', 'storage/app/uploads/photo.png 未被覆盖');
$add($read($siteDir.'/storage/logs/laravel.log') === "[2026-01-01] old log line\n", 'storage/logs/laravel.log 未被覆盖');
$add($read($siteDir.'/storage/framework/sessions/sess_abc123') === 'OLD-SESSION', 'storage/framework/sessions 未被覆盖');
$add($read($siteDir.'/storage/app/update-state.json') !== null, 'storage/app/update-state.json 未被覆盖');

// bootstrap/cache：包内没有 bootstrap/cache 内容 → 旧缓存文件由 swap 末尾主动清理（触发重建）
$cachePkg = $read($siteDir.'/bootstrap/cache/packages.php');
$add($cachePkg === null || ! str_contains((string) $cachePkg, 'old-compiled-cache'),
    'bootstrap/cache 旧编译缓存已被清理（触发 Laravel 重建）', $cachePkg === null ? '文件已删除' : '内容='.$cachePkg);

// 代码被新包覆盖
$helpers = (string) $read($siteDir.'/app/Support/helpers.php');
$add(! str_contains($helpers, 'STALE-MARKER-OLD-CODE'), '包内同名文件已覆盖旧文件');
$add(str_contains($helpers, 'site_setting'), '覆盖后内容来自新包（helper 函数仍在）');
$add(preg_match("/'version'\s*=>\s*'(v[^']+)'/", (string) $read($siteDir.'/config/version.php'), $m) === 1
    && $m[1] === $pkgTag, 'config/version.php 已更新为新版本', $m[1] ?? '');
$add(is_file($siteDir.'/vendor/autoload.php'), 'vendor/ 已就位（自包含依赖）');
$add(is_file($siteDir.'/Modules/UpdateCenter/app/Services/SelfUpdater.php'), 'Modules/ 已就位');

// modules_statuses.json 合并语义：旧站点的启停状态保留，新包条目补齐
$statuses = json_decode((string) $read($siteDir.'/modules_statuses.json'), true) ?: [];
$pkgStatuses = json_decode((string) $read($extractDir.'/modules_statuses.json'), true) ?: [];
$add(($statuses['AutoSeo'] ?? null) === false, 'modules_statuses.json：旧站点已关闭的模块保持关闭');
$mergedOk = true;
foreach ($pkgStatuses as $module => $enabled) {
    if (! array_key_exists($module, $statuses)) {
        $mergedOk = false;
    }
}
$add($mergedOk, 'modules_statuses.json：新包条目已合并进来', '包内条目='.count($pkgStatuses));

// 包内 storage 骨架不会落到线上站点（storage 整棵保留）
$add($read($siteDir.'/storage/app/uploads/photo.png') === 'OLD-UPLOADED-PNG', '包内 storage 骨架未覆盖线上 storage');

// 已知特性：新版本删掉的文件不会被清理（覆盖式更新语义）
$add(is_file($siteDir.'/app/Legacy/OldRemoved.php'), '【特性】旧版本遗留文件保留（覆盖式更新不删文件）');

// ---------------------------------------------------------------- 输出
$pass = 0;
$fail = 0;
echo "假站点：{$siteDir}\n";
echo 'swap 日志：'.implode(' | ', $log)."\n\n";
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
    new RecursiveDirectoryIterator($siteDir, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST,
);
foreach ($items as $item) {
    $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
}
@rmdir($siteDir);

exit($fail === 0 ? 0 : 1);
