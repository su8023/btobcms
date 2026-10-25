<?php

namespace Tests\Feature;

use App\Services\PluginUploader;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;
use ZipArchive;

/**
 * 插件上传安装服务测试。
 *
 * 注意：服务会修改真实项目的 composer.json 与 modules_statuses.json，
 * setUp 先备份原内容，tearDown 显式清理 + 精确恢复，确保零残留。
 */
class PluginUploadTest extends TestCase
{
    protected string $composerBackup;

    protected string $statusesBackup;

    protected string $testDir;

    protected const PLUGIN = 'MyTestPlugin';

    protected function setUp(): void
    {
        parent::setUp();

        // 备份真实项目文件（测试结束后精确恢复）
        $this->composerBackup = (string) file_get_contents(base_path('composer.json'));
        $this->statusesBackup = (string) file_get_contents(base_path('modules_statuses.json'));

        $this->testDir = storage_path('app/plugin-upload-tests');

        if (! is_dir($this->testDir)) {
            mkdir($this->testDir, 0777, true);
        }

        // 清理上次运行可能留下的残留，保证用例间互不污染
        $this->cleanupLeftovers();

        $this->buildValidZip();
        $this->buildTraversalZip();
    }

    protected function tearDown(): void
    {
        // 先恢复测试前的原始内容，再做兜底清理（清除备份里可能已存在的本插件残留）
        file_put_contents(base_path('composer.json'), $this->composerBackup);
        file_put_contents(base_path('modules_statuses.json'), $this->statusesBackup);
        $this->cleanupLeftovers();

        @unlink($this->testDir.'/valid-plugin.zip');
        @unlink($this->testDir.'/traversal-plugin.zip');
        @rmdir($this->testDir);

        parent::tearDown();
    }

    /* ---------------------------------- 用例 ---------------------------------- */

    public function test_upload_installs_plugin_and_registers_mapping(): void
    {
        $result = app(PluginUploader::class)->upload($this->testDir.'/valid-plugin.zip', skipAutoload: true);

        $this->assertTrue($result['ok'], '安装失败：'.($result['error'] ?? ''));

        // 目录与文件落位
        $this->assertFileExists(base_path('Modules/MyTestPlugin/module.json'));
        $this->assertFileExists(base_path('Modules/MyTestPlugin/app/Providers/MyTestPluginServiceProvider.php'));

        // modules_statuses.json 已启用
        $statuses = json_decode((string) file_get_contents(base_path('modules_statuses.json')), true);
        $this->assertTrue($statuses['MyTestPlugin']);

        // composer.json 已注册 PSR-4 三行映射
        $psr4 = json_decode((string) file_get_contents(base_path('composer.json')), true)['autoload']['psr-4'];
        $this->assertSame('Modules/MyTestPlugin/app/', $psr4['Modules\\MyTestPlugin\\']);
        $this->assertSame('Modules/MyTestPlugin/database/factories/', $psr4['Modules\\MyTestPlugin\\Database\\Factories\\']);
        $this->assertSame('Modules/MyTestPlugin/database/seeders/', $psr4['Modules\\MyTestPlugin\\Database\\Seeders\\']);

        // 返回元信息
        $this->assertSame('MyTestPlugin', $result['name']);
        $this->assertSame('integration', $result['category']);
        $this->assertSame('1.0.0', $result['version']);
    }

    public function test_duplicate_upload_is_rejected(): void
    {
        $uploader = app(PluginUploader::class);

        $this->assertTrue(
            $uploader->upload($this->testDir.'/valid-plugin.zip', skipAutoload: true)['ok'],
            '首次安装应成功'
        );

        $again = $uploader->upload($this->testDir.'/valid-plugin.zip', skipAutoload: true);

        $this->assertFalse($again['ok']);
        $this->assertStringContainsString('已存在', $again['error']);
    }

    public function test_traversal_zip_is_rejected_without_writing_files(): void
    {
        $result = app(PluginUploader::class)->upload($this->testDir.'/traversal-plugin.zip');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('路径穿越', $result['error']);

        // 安全校验先于解压：任何探测文件与插件目录都不允许被写出
        $this->assertFileDoesNotExist(base_path('evil.php'));
        $this->assertFileDoesNotExist(base_path('Modules/evil.php'));
        $this->assertFileDoesNotExist(base_path('Modules/evil2.php'));
        $this->assertDirectoryDoesNotExist(base_path('Modules/MyTestPlugin'));
    }

    public function test_delete_removes_directory_mapping_and_status(): void
    {
        $uploader = app(PluginUploader::class);

        $this->assertTrue(
            $uploader->upload($this->testDir.'/valid-plugin.zip', skipAutoload: true)['ok']
        );

        // 模拟在插件中心停用（delete 仅允许停用状态下执行）
        $statusesPath = base_path('modules_statuses.json');
        $statuses = json_decode((string) file_get_contents($statusesPath), true);
        $statuses['MyTestPlugin'] = false;
        file_put_contents($statusesPath, json_encode($statuses, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        $result = $uploader->delete('MyTestPlugin');

        $this->assertTrue($result['ok'], '删除失败：'.($result['error'] ?? ''));

        $this->assertDirectoryDoesNotExist(base_path('Modules/MyTestPlugin'));

        $psr4 = json_decode((string) file_get_contents(base_path('composer.json')), true)['autoload']['psr-4'];
        $this->assertArrayNotHasKey('Modules\\MyTestPlugin\\', $psr4);
        $this->assertArrayNotHasKey('Modules\\MyTestPlugin\\Database\\Factories\\', $psr4);
        $this->assertArrayNotHasKey('Modules\\MyTestPlugin\\Database\\Seeders\\', $psr4);

        $statuses = json_decode((string) file_get_contents($statusesPath), true);
        $this->assertArrayNotHasKey('MyTestPlugin', $statuses);
    }

    /* ---------------------------------- 辅助 ---------------------------------- */

    /**
     * 构造最小合法插件 zip：MyTestPlugin/ 一级目录 + module.json + 最小 Provider 类。
     */
    protected function buildValidZip(): void
    {
        $zipPath = $this->testDir.'/valid-plugin.zip';
        @unlink($zipPath);

        $moduleJson = json_encode([
            'name' => 'MyTestPlugin',
            'alias' => 'mytestplugin',
            'description' => '上传安装功能测试用最小插件',
            'priority' => 0,
            'providers' => ['Modules\\MyTestPlugin\\Providers\\MyTestPluginServiceProvider'],
            'files' => [],
            'category' => 'integration',
            'version' => '1.0.0',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $provider = <<<'PHP'
<?php

namespace Modules\MyTestPlugin\Providers;

use Illuminate\Support\ServiceProvider;

class MyTestPluginServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
    }
}
PHP;

        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('MyTestPlugin/module.json', $moduleJson."\n");
        $zip->addFromString('MyTestPlugin/app/Providers/MyTestPluginServiceProvider.php', $provider."\n");
        $zip->close();
    }

    /**
     * 构造路径穿越 zip：合法 manifest + 前置 ../ 与中段 ../ 条目，必须被整体拒绝。
     */
    protected function buildTraversalZip(): void
    {
        $zipPath = $this->testDir.'/traversal-plugin.zip';
        @unlink($zipPath);

        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('MyTestPlugin/module.json', '{"name":"MyTestPlugin","providers":["Modules\\\\MyTestPlugin\\\\Providers\\\\MyTestPluginServiceProvider"]}');
        $zip->addFromString('../evil.php', '<?php // 前置路径穿越探测文件，绝不允许被写出');
        $zip->addFromString('MyTestPlugin/../../evil2.php', '<?php // 中段路径穿越探测文件，绝不允许被写出');
        $zip->close();
    }

    /**
     * 清理本测试插件在项目中的所有痕迹；仅在确有残留时改写文件，避免无谓重排格式。
     */
    protected function cleanupLeftovers(): void
    {
        $dir = base_path('Modules/MyTestPlugin');

        if (is_dir($dir)) {
            $this->removeDirectory($dir);
        }

        $composerPath = base_path('composer.json');
        $composer = json_decode((string) file_get_contents($composerPath), true);

        if (is_array($composer) && isset($composer['autoload']['psr-4']) && is_array($composer['autoload']['psr-4'])) {
            $removed = false;

            foreach ([
                'Modules\\MyTestPlugin\\',
                'Modules\\MyTestPlugin\\Database\\Factories\\',
                'Modules\\MyTestPlugin\\Database\\Seeders\\',
            ] as $key) {
                if (array_key_exists($key, $composer['autoload']['psr-4'])) {
                    unset($composer['autoload']['psr-4'][$key]);
                    $removed = true;
                }
            }

            if ($removed) {
                file_put_contents($composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
            }
        }

        $statusesPath = base_path('modules_statuses.json');
        $statuses = json_decode((string) file_get_contents($statusesPath), true);

        if (is_array($statuses) && array_key_exists('MyTestPlugin', $statuses)) {
            unset($statuses['MyTestPlugin']);
            file_put_contents($statusesPath, json_encode($statuses, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        }
    }

    protected function removeDirectory(string $dir): void
    {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }
}
