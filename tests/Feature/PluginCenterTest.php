<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nwidart\Modules\Facades\Module;
use Tests\TestCase;

class PluginCenterTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::firstOrFail();
    }

    public function test_plugin_center_renders_with_categorized_plugins(): void
    {
        $response = $this->actingAs($this->admin)->get('/admin/plugin-center');

        $response->assertOk();

        $content = $response->getContent();

        // 全部 5 个插件出现
        foreach (['FeishuNotifier', 'MailNotifier', 'WhatsAppChat', 'AiSeoAuditor', 'AiContentWriter'] as $name) {
            $this->assertStringContainsString($name, $content);
        }

        // 类别分组标题（英文界面）
        $this->assertStringContainsString('Notifications', $content);
        $this->assertStringContainsString('AI', $content);
        $this->assertStringContainsString('Customer Service', $content);

        // 启用状态徽标
        $this->assertStringContainsString('Enabled', $content);
    }

    public function test_plugin_toggle_disables_and_reenables(): void
    {
        $module = Module::find('FeishuNotifier');
        $this->assertTrue($module->isEnabled());

        try {
            $module->disable();
            $this->assertFalse(Module::find('FeishuNotifier')->isEnabled());

            // 停用后插件中心仍列出该插件，但显示已停用
            $content = $this->actingAs($this->admin)->get('/admin/plugin-center')->getContent();
            $this->assertStringContainsString('FeishuNotifier', $content);
            $this->assertStringContainsString('Disabled', $content);
        } finally {
            Module::find('FeishuNotifier')->enable();
        }

        $this->assertTrue(Module::find('FeishuNotifier')->isEnabled());
        $this->assertSame(true, json_decode(file_get_contents(base_path('modules_statuses.json')), true)['FeishuNotifier']);
    }

    public function test_module_json_declares_category_and_version(): void
    {
        foreach (['FeishuNotifier', 'MailNotifier', 'WhatsAppChat', 'AiSeoAuditor', 'AiContentWriter'] as $name) {
            $manifest = json_decode((string) file_get_contents(base_path("Modules/{$name}/module.json")), true);

            $this->assertArrayHasKey('category', $manifest, "{$name} missing category");
            $this->assertArrayHasKey('version', $manifest, "{$name} missing version");
            $this->assertNotSame('', $manifest['description'], "{$name} missing description");
        }
    }
}
