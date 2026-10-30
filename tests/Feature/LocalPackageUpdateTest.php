<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\UpdateCenter\Services\SelfUpdater;
use Tests\TestCase;
use ZipArchive;

/**
 * 本地上传安装包更新（不依赖 GitHub API 的自助通路）。
 */
class LocalPackageUpdateTest extends TestCase
{
    use RefreshDatabase;

    protected string $fakeBase;

    protected string $fakeUpdateDir;

    protected string $fakeLog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate')->run();

        $this->fakeBase = sys_get_temp_dir().'/localpkg-'.uniqid();
        $this->fakeUpdateDir = $this->fakeBase.'/storage/app/update';
        $this->fakeLog = $this->fakeBase.'/storage/logs/update.log';
        foreach ([$this->fakeUpdateDir, dirname($this->fakeLog)] as $dir) {
            @mkdir($dir, 0777, true);
        }

        file_put_contents($this->fakeBase.'/artisan', 'stub');
        file_put_contents($this->fakeBase.'/version.php', "<?php return ['version' => 'v0.9.0'];");
        file_put_contents($this->fakeBase.'/.env', 'APP_KEY=old-secret');
        file_put_contents($this->fakeBase.'/modules_statuses.json', json_encode(['AutoSeo' => false]));
        @mkdir($this->fakeBase.'/bootstrap/cache', 0775, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->fakeBase));
        parent::tearDown();
    }

    private function updater(): SelfUpdater
    {
        return new SelfUpdater(
            basePath: $this->fakeBase,
            updateDir: $this->fakeUpdateDir,
            logPath: $this->fakeLog,
            repo: '',
            token: '',
            skipArtisan: true,
        );
    }

    /** 生成一个合法的自包含包 */
    private function makePackage(): string
    {
        $path = sys_get_temp_dir().'/localpkg-src-'.uniqid().'.zip';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('artisan', 'stub');
        $zip->addFromString('composer.json', '{}');
        $zip->addFromString('bootstrap/app.php', '<?php');
        $zip->addFromString('vendor/autoload.php', '<?php');
        $zip->addFromString('public/index.php', '<?php');
        $zip->addFromString('app/New.php', '<?php // new');
        $zip->addFromString('app/Padding.bin', random_bytes(4096));
        $zip->close();

        return $path;
    }

    public function test_local_package_install_skips_download_step(): void
    {
        $uploaded = $this->makePackage();

        $log = $this->updater()->installFromLocalPackage($uploaded, 'v9.9.9');
        $joined = implode("\n", $log);

        $this->assertStringContainsString('本地上传安装包', $joined);
        $this->assertStringContainsString('解压完成', $joined);
        $this->assertStringNotContainsString('—— 步骤：download', $joined, '本地上传应跳过下载步骤');
        $this->assertFileExists($this->fakeBase.'/app/New.php');
        $this->assertSame('APP_KEY=old-secret', (string) file_get_contents($this->fakeBase.'/.env'));
    }

    public function test_local_package_rejects_invalid_zip(): void
    {
        $bad = $this->fakeBase.'/bad.zip';
        file_put_contents($bad, 'not a zip at all');

        $this->expectException(\RuntimeException::class);
        $this->updater()->installFromLocalPackage($bad, 'v9.9.9');
    }

    public function test_local_package_rejects_invalid_tag(): void
    {
        $uploaded = $this->makePackage();

        $this->expectException(\RuntimeException::class);
        $this->updater()->installFromLocalPackage($uploaded, '../../etc/passwd');
    }
}
