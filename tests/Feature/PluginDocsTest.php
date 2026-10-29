<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 插件使用教程约定：Modules\{Name}\Support\Docs::content() →
 * 插件中心卡片展开块 + 插件设置页顶部折叠区。
 */
class PluginDocsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(User::first());
    }

    public function test_every_plugin_has_documentation(): void
    {
        foreach (\Nwidart\Modules\Facades\Module::all() as $module) {
            $name = $module->getName();
            $docs = \App\Support\PluginDocs::content($name);

            $this->assertNotNull($docs, "插件 {$name} 缺少使用教程（Modules/{$name}/app/Support/Docs.php）");
            $this->assertStringContainsString('<h4>', (string) $docs, "插件 {$name} 教程应有结构化标题");
        }
    }

    public function test_plugin_center_renders_docs_blocks(): void
    {
        $this->get('/admin/plugin-center')
            ->assertOk()
            ->assertSee('使用教程')
            ->assertSee('配置步骤', false);
    }

    public function test_settings_page_shows_docs_section(): void
    {
        $this->get('/admin/plugins/AiContentWriter/settings')
            ->assertOk()
            ->assertSee('使用教程')
            ->assertSee('AI 写作室', false);
    }

    public function test_unknown_plugin_returns_null(): void
    {
        $this->assertNull(\App\Support\PluginDocs::content('NotAModule'));
    }
}
