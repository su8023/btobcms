<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PluginGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Modules\AiContentWriter\Support\RecordTranslation;
use Nwidart\Modules\Contracts\ActivatorInterface;
use Nwidart\Modules\Facades\Module;
use Tests\TestCase;

/**
 * 核心层引用插件的门禁测试。
 *
 * 背景：插件中心的「删除插件」会真的删掉 Modules/{Name} 目录，
 * 核心代码若直接静态调用插件类（Foo::bar()），类不存在时 PHP 抛
 * Error: Class "..." not found —— 后台首页/列表页直接 500。
 * PluginGuard 是这层防护，本测试锁住它的语义。
 */
class PluginGuardTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    /** 原始模块启停状态，测试后还原（FileActivator 会写盘） */
    protected string $statusesBackup = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::firstOrFail();

        $statusesPath = base_path('modules_statuses.json');
        $this->statusesBackup = (string) file_get_contents($statusesPath);
    }

    protected function tearDown(): void
    {
        // 还原启停状态，避免污染后续测试与工作区文件
        if ($this->statusesBackup !== '') {
            file_put_contents(base_path('modules_statuses.json'), $this->statusesBackup);
        }

        parent::tearDown();
    }

    public function test_missing_class_is_not_available(): void
    {
        // 类不存在 → false，且不抛异常（这是插件目录被删的真实场景）
        $this->assertFalse(
            PluginGuard::available('Modules\\DefinitelyMissing\\Models\\Foo')
        );
    }

    public function test_missing_class_with_module_name_is_not_available(): void
    {
        $this->assertFalse(
            PluginGuard::available('Modules\\DefinitelyMissing\\Models\\Foo', 'DefinitelyMissing')
        );
    }

    public function test_existing_class_without_module_name_is_available(): void
    {
        // 只判类存在性时，真实存在的插件类应放行
        $this->assertTrue(PluginGuard::available(RecordTranslation::class));
    }

    public function test_existing_class_with_enabled_module_is_available(): void
    {
        $this->assertTrue(
            PluginGuard::available(RecordTranslation::class, 'AiContentWriter')
        );
    }

    public function test_deleted_module_does_not_throw_and_is_not_available(): void
    {
        // 模块目录被删时 Module::isEnabled() 会抛 ModuleNotFoundException
        // （FileRepository::findOrFail），守卫必须吞掉并返回 false
        $this->assertFalse(
            PluginGuard::available(RecordTranslation::class, 'AiContentWriterDeleted')
        );
    }

    public function test_disabled_module_is_not_available(): void
    {
        $this->disableModule('AiContentWriter');

        $this->assertFalse(
            PluginGuard::available(RecordTranslation::class, 'AiContentWriter')
        );
    }

    public function test_dashboard_renders_when_optional_plugin_is_disabled(): void
    {
        // GscPulse 停用后，新手清单那一项应整项消失（既完成不了、链接也 404），
        // 仪表盘本身必须正常渲染
        $this->disableModule('GscPulse');

        $this->actingAs($this->admin)
            ->get('/admin')
            ->assertOk()
            ->assertDontSee('连接谷歌搜索看板');
    }

    public function test_products_and_categories_pages_render_when_translation_plugin_is_disabled(): void
    {
        // AiContentWriter 停用后，产品/分类列表的翻译徽章列与操作按钮应消失，
        // 页面不得 500
        $this->disableModule('AiContentWriter');

        $this->actingAs($this->admin)
            ->get('/admin/products')
            ->assertOk();

        $this->actingAs($this->admin)
            ->get('/admin/categories')
            ->assertOk();
    }

    public function test_sitemap_renders_when_trust_builder_table_is_missing(): void
    {
        // 全新库若 TrustBuilder 从未启用过，迁移没跑 → trust_cases 表不存在。
        // 此时 class_exists(TrustCase) 仍为 true（类文件在盘上），
        // 直接 count() 会抛 QueryException，/sitemap.xml 与
        // /sitemap-cases.xml 全部 500。sitemap 必须优雅降级。
        Schema::dropIfExists('trust_cases');
        Cache::forget('sitemap-shard-counts');

        $this->get('/sitemap.xml')
            ->assertOk();

        // cases 分片此时不应存在（表都没有，查不出链接）
        $this->get('/sitemap-cases.xml')
            ->assertNotFound();
    }

    public function test_pages_render_when_trust_builder_tables_are_missing(): void
    {
        // TrustBuilder 的信任条是全站插槽（footer_before），每页都渲染。
        // 表不存在时它照查不误 → QueryException → 全站每一页都 500
        // （连 404 页都渲染不出来，因为 404 页本身也要渲染信任条）。
        Schema::dropIfExists('trust_cases');
        Schema::dropIfExists('trust_certifications');
        Cache::forget('trust-builder:trust-bar');

        $this->get('/')->assertOk();
        $this->get('/contact')->assertOk();
        $this->get('/blog')->assertOk();
        $this->get('/nonexistent-page-xyz')->assertNotFound();
    }

    /**
     * 停用模块。
     *
     * 不直接改 modules_statuses.json：FileActivator 是容器单例，
     * 构造时就已把状态读进内存，改文件不会影响已存在的实例，
     * 还会污染工作区文件。这里改为替换 activator 单例（内存态），
     * 让 isEnabled() 立刻看到新状态。
     */
    private function disableModule(string $name): void
    {
        $module = Module::find($name);

        if ($module === null) {
            $this->fail("模块 {$name} 不存在，测试前提不成立");
        }

        // 换掉 activator 单例：新的 FileActivator 从当前 JSON 读状态，
        // 我们在读之前把它置为「停用」
        $activator = new class($name) implements ActivatorInterface
        {
            public function __construct(public string $disabledName) {}

            public function enable(\Nwidart\Modules\Module $module): void {}

            public function disable(\Nwidart\Modules\Module $module): void {}

            public function hasStatus(\Nwidart\Modules\Module|string $module, bool $status): bool
            {
                $name = $module instanceof \Nwidart\Modules\Module ? $module->getName() : $module;

                if ($name === $this->disabledName) {
                    return $status === false;
                }

                return $status === true;
            }

            public function setActive(\Nwidart\Modules\Module $module, bool $active): void {}

            public function setActiveByName(string $name, bool $active): void {}

            public function delete(\Nwidart\Modules\Module $module): void {}

            public function reset(): void {}
        };

        $this->app->instance(ActivatorInterface::class, $activator);
    }
}
