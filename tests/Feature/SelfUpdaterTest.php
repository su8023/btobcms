<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\UpdateCenter\Services\SelfUpdater;
use Tests\TestCase;
use ZipArchive;

/**
 * WordPress 式自更新器（SelfUpdater）：
 * 用临时目录模拟旧站骨架，验证自包含包的「保留式覆盖 + 状态断点 + 幂等恢复」。
 */
class SelfUpdaterTest extends TestCase
{
    protected string $fakeBase;

    protected string $fakeUpdateDir;

    protected string $fakeLog;

    protected function setUp(): void
    {
        parent::setUp();

        // 注：RefreshDatabase 的 refreshDatabase() 钩子在本类偶发不触发
        // （Laravel 13 trait 钩子收集怪癖，实证见提交历史），显式迁移确保表必然存在
        $this->artisan("migrate:fresh")->run();
        $this->seed();
        $this->actingAs(User::first());

        // 模拟旧站骨架：version.php / modules_statuses.json / .env / storage / artisan
        $this->fakeBase = sys_get_temp_dir().'/selfupdater-base-'.uniqid();
        $this->fakeUpdateDir = $this->fakeBase.'/storage/app/update';
        $this->fakeLog = $this->fakeBase.'/storage/logs/update.log';
        foreach ([$this->fakeUpdateDir, dirname($this->fakeLog)] as $dir) {
            @mkdir($dir, 0777, true);
        }

        file_put_contents($this->fakeBase.'/version.php', "<?php return ['version' => 'v0.9.0'];");
        file_put_contents($this->fakeBase.'/.env', 'APP_KEY=old-secret');
        file_put_contents($this->fakeBase.'/modules_statuses.json', json_encode(['AutoSeo' => false], JSON_PRETTY_PRINT));
        @mkdir($this->fakeBase.'/storage/app/public', 0775, true);
        file_put_contents($this->fakeBase.'/storage/app/public/old-upload.txt', 'user data');
        @mkdir($this->fakeBase.'/bootstrap/cache', 0775, true);
        file_put_contents($this->fakeBase.'/bootstrap/cache/config.php', 'stale');
        file_put_contents($this->fakeBase.'/artisan', 'stub');
    }

    /** 构建一个「新版本」包：新版本号 + 新文件 + 新模块，含默认模块状态 */
    private function makePackageZip(): string
    {
        $staging = sys_get_temp_dir().'/selfupdater-pkg-'.uniqid();
        @mkdir($staging, 0775, true);
        file_put_contents($staging.'/version.php', "<?php return ['version' => 'v9.9.9'];");
        file_put_contents($staging.'/artisan', 'stub');
        @mkdir($staging.'/Modules/NewPlugin/app/Providers', 0775, true);
        file_put_contents($staging.'/modules_statuses.json', json_encode([
            'AutoSeo' => true,          // 旧站存了 false：合并语义应保留 false
            'NewPlugin' => true,        // 新包新增的模块：缺失时应补进
        ], JSON_PRETTY_PRINT));
        $zipPath = $staging.'/package.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE);
        foreach (['version.php', 'artisan', 'modules_statuses.json'] as $f) {
            $zip->addFile($staging.'/'.$f, $f);
        }
        // 完整性必需文件（swap 前会硬校验）
        $zip->addFromString('composer.json', '{}');
        $zip->addFromString('bootstrap/app.php', '<?php');
        $zip->addFromString('vendor/autoload.php', '<?php');
        $zip->addFromString('public/index.php', '<?php');
        $zip->addFromString('Modules/NewPlugin/app/Providers/Provider.php', '<?php // new module');
        $zip->close();

        return $zipPath;
    }

    private function makeUpdater(bool $skipArtisan = true): SelfUpdater
    {
        return new SelfUpdater(
            basePath: $this->fakeBase,
            updateDir: $this->fakeUpdateDir,
            logPath: $this->fakeLog,
            repo: 'acme/btobcms',
            token: 'test-token',
            skipArtisan: $skipArtisan,
        );
    }

    public function test_run_swaps_files_with_preserve_and_merge(): void
    {
        $zipPath = $this->makePackageZip();

        // 把包放到 update 目录模拟「已下载」步骤（同时测 download 幂等跳过）
        copy($zipPath, $this->fakeUpdateDir.'/v9.9.9.zip');

        $log = $this->makeUpdater()->run('v9.9.9');

        // 版本文件被覆盖
        $this->assertStringContainsString('v9.9.9', (string) file_get_contents($this->fakeBase.'/version.php'));
        // 新模块文件到位
        $this->assertFileExists($this->fakeBase.'/Modules/NewPlugin/app/Providers/Provider.php');
        // .env 保留
        $this->assertSame('APP_KEY=old-secret', (string) file_get_contents($this->fakeBase.'/.env'));
        // storage 用户数据保留
        $this->assertFileExists($this->fakeBase.'/storage/app/public/old-upload.txt');
        // modules_statuses 合并：既有值保留 + 缺失的补进
        $statuses = json_decode((string) file_get_contents($this->fakeBase.'/modules_statuses.json'), true);
        $this->assertFalse($statuses['AutoSeo'], '既有模块启停状态必须保留');
        $this->assertTrue($statuses['NewPlugin'], '新模块补进默认启用');
        // 旧编译缓存被清（防引用旧路径 500）
        $this->assertFileDoesNotExist($this->fakeBase.'/bootstrap/cache/config.php');
        // 日志完整
        $this->assertStringContainsString('更新完成 ✓ 当前版本 v9.9.9', implode("\n", $log));
    }

    public function test_state_file_records_progress_and_done(): void
    {
        copy($this->makePackageZip(), $this->fakeUpdateDir.'/v9.9.9.zip');
        $updater = $this->makeUpdater();
        $updater->run('v9.9.9');

        $state = $updater->state();
        $this->assertSame('done', $state['status']);
        $this->assertFalse($updater->isResumable());
    }

    /** saveState 的原子写在 rename 覆盖失败（Windows 偶发）时必须回退直写 */
    public function test_state_file_consecutive_writes_always_persist(): void
    {
        $updater = $this->makeUpdater();

        for ($i = 0; $i < 5; $i++) {
            $updater->saveState('v9.9.9', 'step'.$i, 'running', ['round-'.$i]);
            $state = $updater->state();
            $this->assertSame('running', $state['status']);
            $this->assertSame('step'.$i, $state['step']);
        }
    }

    /** 更新成功后自动清理：旧版本安装包、上传原件、过期备份（保留最近 2 份） */
    public function test_finish_prunes_old_packages_uploads_and_backups(): void
    {
        copy($this->makePackageZip(), $this->fakeUpdateDir.'/v9.9.9.zip');
        // 残留：更旧版本的包 + 上传原件 + 3 份旧备份
        file_put_contents($this->fakeUpdateDir.'/v1.0.0.zip', 'old-package');
        @mkdir(dirname($this->fakeUpdateDir).'/update-uploads', 0775, true);
        file_put_contents(dirname($this->fakeUpdateDir).'/update-uploads/btobcms-v1.0.0.zip', 'uploaded');
        $backupRoot = $this->fakeBase.'/storage/app/backups';
        $ages = ['v1.0.0' => 3600, 'v1.0.1' => 2400, 'v1.0.2' => 1200]; // 依次变新，保证 mtime 可区分
        foreach ($ages as $old => $age) {
            @mkdir($backupRoot.'/'.$old, 0775, true);
            file_put_contents($backupRoot.'/'.$old.'/f.txt', 'x');
            touch($backupRoot.'/'.$old, time() - $age);
        }

        $updater = $this->makeUpdater();
        $updater->run('v9.9.9');

        $this->assertFileDoesNotExist($this->fakeUpdateDir.'/v1.0.0.zip');
        $this->assertFileDoesNotExist(dirname($this->fakeUpdateDir).'/update-uploads/btobcms-v1.0.0.zip');
        $this->assertFileDoesNotExist($backupRoot.'/v1.0.0');
        $this->assertFileDoesNotExist($backupRoot.'/v1.0.1');
        // 本次更新的 v9.9.9 备份 + 最新的旧备份 v1.0.2 = 恰好保留 2 份
        $remaining = glob($backupRoot.'/*', GLOB_ONLYDIR);
        $this->assertCount(2, $remaining, '备份必须只保留最近 2 份，实际：'.implode(',', $remaining));
    }

    public function test_failure_interrupts_and_resume_completes(): void
    {
        $this->makePackageZip();
        $updater = $this->makeUpdater();

        // 人为制造失败：把下载步骤要用的包删掉 → swap 读不到 extract 也会失败
        // 这里用更直接的：下载步骤 GitHub 请求失败（repo 为空时直接抛）
        $broken = new SelfUpdater(
            basePath: $this->fakeBase,
            updateDir: $this->fakeUpdateDir,
            logPath: $this->fakeLog,
            repo: '', // 未配置仓库 → download 抛错
            token: '',
            skipArtisan: true,
        );

        try {
            $broken->run('v9.9.9');
            $this->fail('应当抛出异常');
        } catch (\RuntimeException) {
        }

        $state = $broken->state();
        $this->assertSame('interrupted', $state['status']);
        $this->assertSame('download', $state['step']);
        $this->assertTrue($broken->isResumable());

        // 恢复：放入包后从断点继续 → 完成
        copy($this->makePackageZip(), $this->fakeUpdateDir.'/v9.9.9.zip');
        $log = $broken->resume();

        $this->assertStringContainsString('从步骤 download 恢复', implode("\n", $log));
        $this->assertStringContainsString('v9.9.9', (string) file_get_contents($this->fakeBase.'/version.php'));
    }

    public function test_abandon_stops_resumable(): void
    {
        $broken = new SelfUpdater(
            basePath: $this->fakeBase,
            updateDir: $this->fakeUpdateDir,
            logPath: $this->fakeLog,
            repo: '',
            token: '',
            skipArtisan: true,
        );
        try {
            $broken->run('v9.9.9');
        } catch (\RuntimeException) {
        }

        $broken->abandon();
        $this->assertFalse($broken->isResumable());
    }
}
