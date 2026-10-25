<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ThemeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use ZipArchive;

class ThemeCenterTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected string $fixtureDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::firstOrFail();
        $this->fixtureDir = storage_path('app/theme-upload-tests');
        @mkdir($this->fixtureDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // 完整清理测试安装的主题
        foreach (['teal-test', 'evil-theme'] as $slug) {
            @rmdir(base_path('themes/'.$slug));
            @File::deleteDirectory(base_path('themes/'.$slug));
            @File::deleteDirectory(public_path('themes/'.$slug));
        }

        File::deleteDirectory($this->fixtureDir);

        // 恢复默认激活主题
        \App\Models\Setting::where('key', 'site.active_theme')->delete();

        parent::tearDown();
    }

    protected function makeThemeZip(string $zipPath, string $slug, string $prefix = '', ?array $extraEntry = null): void
    {
        $root = $this->fixtureDir.'/'.$slug;
        @mkdir($root.($prefix !== '' ? '/'.$prefix : '').'/assets/css', 0755, true);

        $manifestDir = $root.($prefix !== '' ? '/'.$prefix : '');
        file_put_contents($manifestDir.'/theme.json', json_encode([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'version' => '1.0.0',
            'author' => 'Test',
            'description' => 'Test theme',
        ]));
        file_put_contents($manifestDir.'/assets/css/theme.css', ':root { --primary: #123456; }');

        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $zip->addFile($file->getPathname(), ltrim(str_replace($root, '', $file->getPathname()), '/\\'));
            }
        }

        if ($extraEntry !== null) {
            [$name, $content] = $extraEntry;
            $zip->addFromString($name, $content);
        }

        $zip->close();
    }

    public function test_theme_center_lists_builtin_themes(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/theme-center')
            ->assertOk()
            ->assertSee('Default')
            ->assertSee('Focus')
            ->assertSee('Midnight')
            ->assertSee('Active');
    }

    public function test_activate_theme_publishes_assets_and_front_site_loads_theme_css(): void
    {
        // 通过插件中心同款 Livewire 组件调用激活（含设置写入与资源发布）
        Livewire::actingAs($this->admin)
            ->test(\App\Filament\Pages\ThemeCenter::class)
            ->call('activate', 'midnight');

        $this->assertSame('midnight', app(ThemeManager::class)->active());
        $this->assertDatabaseHas('settings', ['key' => 'site.active_theme']);

        // 前台加载主题 CSS
        $this->get('/')->assertOk()->assertSee('/themes/midnight/css/theme.css', false);
    }

    public function test_uploaded_theme_zip_installs_and_can_activate(): void
    {
        $zipPath = $this->fixtureDir.'/teal-test.zip';
        $this->makeThemeZip($zipPath, 'teal-test');

        $result = app(ThemeManager::class)->install($zipPath);

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertFileExists(base_path('themes/teal-test/theme.json'));
        $this->assertFileExists(public_path('themes/teal-test/css/theme.css'));

        // 主题中心列表出现新主题
        $this->actingAs($this->admin)
            ->get('/admin/theme-center')
            ->assertOk()
            ->assertSee('Teal-test');

        // 服务激活
        app(ThemeManager::class)->activate('teal-test');
        $this->assertSame('teal-test', app(ThemeManager::class)->active());
        $this->get('/')->assertOk()->assertSee('/themes/teal-test/css/theme.css', false);
    }

    public function test_malicious_theme_zip_is_rejected(): void
    {
        $zipPath = $this->fixtureDir.'/evil.zip';

        // 构造含合法 theme.json + 路径穿越条目的 zip
        $this->makeThemeZip($zipPath, 'evil-theme', '', ['../evil.php', '<?php echo "pwned";']);

        $result = app(ThemeManager::class)->install($zipPath);

        $this->assertFalse($result['ok']);
        $this->assertFileDoesNotExist(base_path('evil.php'));
        $this->assertFileDoesNotExist(base_path('themes/evil-theme'));
    }

    public function test_custom_css_from_site_settings_is_injected(): void
    {
        \App\Models\Setting::put('site.custom_css', ':root { --primary: #123123; }');

        $this->get('/')
            ->assertOk()
            ->assertSee('<style>:root { --primary: #123123; }</style>', false);
    }

    public function test_delete_refuses_active_theme(): void
    {
        app(ThemeManager::class)->activate('midnight');

        $result = app(ThemeManager::class)->delete('midnight');

        $this->assertFalse($result['ok']);
        $this->assertFileExists(base_path('themes/midnight/theme.json'));

        app(ThemeManager::class)->activate('default');
    }

    public function test_focus_theme_overrides_homepage_with_lead_form_hero(): void
    {
        app(ThemeManager::class)->activate('focus');

        $this->get('/')
            ->assertOk()
            ->assertSee('fz-hero-grid', false)
            ->assertSee('fz-form-card', false)
            ->assertSee('data-inquiry-form', false)
            ->assertSee('hero-stats', false);

        app(ThemeManager::class)->activate('default');
    }

    public function test_theme_views_override_does_not_leak_between_requests(): void
    {
        // 激活 focus 后切回 default：下一个请求不得残留 focus 的排版类
        app(ThemeManager::class)->activate('focus');
        $this->get('/')->assertOk();

        app(ThemeManager::class)->activate('default');
        $this->get('/')
            ->assertOk()
            ->assertDontSee('fz-hero-grid', false)
            ->assertSee('hero-stats', false);
    }
}
