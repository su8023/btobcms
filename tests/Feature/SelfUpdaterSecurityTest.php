<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\UpdateCenter\Services\SelfUpdater;
use Tests\TestCase;
use ZipArchive;

/**
 * 自更新器安全与健壮性（针对审查发现的 Critical/High）：
 * Zip Slip 防护、坏包识别、数据库文件保护、running 状态可恢复、并发互斥。
 */
class SelfUpdaterSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected string $fakeBase;

    protected string $fakeUpdateDir;

    protected string $fakeLog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate')->run();

        $this->fakeBase = sys_get_temp_dir().'/selfup-sec-'.uniqid();
        $this->fakeUpdateDir = $this->fakeBase.'/storage/app/update';
        $this->fakeLog = $this->fakeBase.'/storage/logs/update.log';
        foreach ([$this->fakeUpdateDir, dirname($this->fakeLog)] as $dir) {
            @mkdir($dir, 0777, true);
        }

        file_put_contents($this->fakeBase.'/artisan', 'stub');
        file_put_contents($this->fakeBase.'/version.php', "<?php return ['version' => 'v0.9.0'];");
        file_put_contents($this->fakeBase.'/.env', 'APP_KEY=old-secret');
        file_put_contents($this->fakeBase.'/modules_statuses.json', json_encode(['AutoSeo' => false]));
        @mkdir($this->fakeBase.'/storage/app/public', 0775, true);
        file_put_contents($this->fakeBase.'/storage/app/public/user.txt', 'user data');
        @mkdir($this->fakeBase.'/database', 0775, true);
        file_put_contents($this->fakeBase.'/database/database.sqlite', 'REAL DB');
        @mkdir($this->fakeBase.'/bootstrap/cache', 0775, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->fakeBase));
        parent::tearDown();
    }

    private function updater(array $overrides = []): SelfUpdater
    {
        return new SelfUpdater(...array_merge([
            'basePath' => $this->fakeBase,
            'updateDir' => $this->fakeUpdateDir,
            'logPath' => $this->fakeLog,
            'repo' => 'acme/btobcms',
            'token' => 'test-token',
            'skipArtisan' => true,
        ], $overrides));
    }

    /** 构造一个含额外条目的包，直接落到更新目录的目标名（跳过 download 步骤） */
    private function makeZip(array $extra = []): string
    {
        $zipPath = $this->fakeUpdateDir.'/v9.9.9.zip';
        @unlink($zipPath);
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('artisan', 'stub');
        $zip->addFromString('composer.json', '{}');
        $zip->addFromString('bootstrap/app.php', '<?php');
        $zip->addFromString('vendor/autoload.php', '<?php');
        $zip->addFromString('public/index.php', '<?php');
        foreach ($extra as $name => $content) {
            $zip->addFromString($name, $content);
        }
        // 填充内容：让包 > 1KB（zipLooksValid 的下限），使 download 步骤判定为「已下载」跳过
        // 用随机字节填充（不可压缩）确保包体积 > 1KB
        $zip->addFromString('app/Padding.bin', random_bytes(4096));
        $zip->close();

        return $zipPath;
    }

    public function test_zip_slip_entry_is_rejected(): void
    {
        // 恶意条目：写到解压目录之外
        $this->makeZip(['../../../../evil.php' => '<?php echo "pwned";']);

        $updater = $this->updater();
        $thrown = null;
        try {
            $updater->run('v9.9.9');
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown, '路径穿越条目必须被拒绝');
        $this->assertStringContainsString('路径穿越', $thrown->getMessage());
        $this->assertFileDoesNotExist(dirname($this->fakeBase, 3).'/evil.php');
    }

    public function test_absolute_path_entry_is_rejected(): void
    {
        $this->makeZip(['/etc/evil.conf' => 'x']);

        $updater = $this->updater();
        $thrown = null;
        try {
            $updater->run('v9.9.9');
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown);
        $this->assertStringContainsString('绝对路径', $thrown->getMessage());
    }

    public function test_incomplete_package_is_rejected_before_overwriting(): void
    {
        // 缺 vendor/autoload.php 的包 → swap 的完整性硬校验拒绝覆盖，站点保持原样
        // （包体积需 > 1KB 才能通过 download 阶段的有效性检查）
        $zipPath = $this->fakeUpdateDir.'/v9.9.9.zip';
        @unlink($zipPath);
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('artisan', 'stub');
        $zip->addFromString('app/Foo.php', '<?php');
        $zip->addFromString('app/Padding.bin', random_bytes(4096));
        $zip->close();

        $updater = $this->updater();
        $thrown = null;
        try {
            $updater->run('v9.9.9');
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown);
        $this->assertStringContainsString('不完整', $thrown->getMessage());
        // 站点未被破坏
        $this->assertSame('APP_KEY=old-secret', (string) file_get_contents($this->fakeBase.'/.env'));
        $this->assertFileDoesNotExist($this->fakeBase.'/app/Foo.php');
    }

    public function test_database_file_and_user_data_are_never_overwritten(): void
    {
        $this->makeZip([
            'database/database.sqlite' => 'HACKED DB',
            'storage/app/public/user.txt' => 'HACKED UPLOAD',
            '.env' => 'APP_KEY=hacked',
        ]);

        $updater = $this->updater();
        $updater->run('v9.9.9');

        $this->assertSame('REAL DB', (string) file_get_contents($this->fakeBase.'/database/database.sqlite'));
        $this->assertSame('user data', (string) file_get_contents($this->fakeBase.'/storage/app/public/user.txt'));
        $this->assertSame('APP_KEY=old-secret', (string) file_get_contents($this->fakeBase.'/.env'));
    }

    public function test_running_state_is_resumable(): void
    {
        // 进程被强杀时状态停在 running（无 catch 分支）→ 必须仍可恢复
        $updater = $this->updater();
        $state = json_decode((string) file_get_contents('php://memory'), true);
        $updater->saveState('v9.9.9', 'download', 'running', ['start']);

        $this->assertTrue($updater->isResumable(), 'running 状态也必须能恢复（进程被强杀的场景）');
    }

    public function test_lock_prevents_concurrent_updates(): void
    {
        $first = $this->updater();
        $second = $this->updater();

        $this->assertTrue($first->acquireLock());
        $this->assertFalse($second->acquireLock(), '第二个更新任务必须被锁挡住');

        $first->releaseLock();
        $this->assertTrue($second->acquireLock());
        $second->releaseLock();
    }

    public function test_invalid_tag_is_rejected(): void
    {
        $updater = $this->updater();

        $this->expectException(\RuntimeException::class);
        $updater->run('../../etc/passwd');
    }

    public function test_partial_download_is_not_treated_as_complete(): void
    {
        // 半截 zip（内容不足 1KB）→ 判定为无效，重新下载而非跳过
        $zipPath = $this->fakeUpdateDir.'/pkg-'.uniqid().'.zip';
        file_put_contents($zipPath, 'truncated');

        $updater = $this->updater();
        $thrown = null;
        try {
            $updater->run('v9.9.9');
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertNotNull($thrown);
        // 坏包被删除（下次会重新下载），而不是卡在坏包上
        $this->assertFileDoesNotExist($this->fakeUpdateDir.'/v9.9.9.zip');
    }

    public function test_rollback_restores_overwritten_files(): void
    {
        $this->makeZip(['app/New.php' => '<?php // new']);

        $updater = $this->updater();
        $updater->run('v9.9.9');
        $this->assertFileExists($this->fakeBase.'/app/New.php');

        // 模拟用户放弃更新 → 回滚到覆盖前
        $updater->rollback();
        $this->assertFileDoesNotExist($this->fakeBase.'/app/New.php');
    }
}
