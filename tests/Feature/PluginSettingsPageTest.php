<?php

namespace Tests\Feature;

use App\Filament\Pages\PluginSettingsPage;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 插件独立设置页（/admin/plugins/{plugin}/settings）：
 * 页面渲染（含字段标签）、404 守卫与保存落库。
 *
 * 注意：新类需 composer dump-autoload 后才能运行，由集成者统一执行。
 */
class PluginSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::firstOrFail();
    }

    public function test_settings_page_renders_plugin_header_and_fields(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/plugins/FeishuNotifier/settings')
            ->assertOk()
            ->assertSee('FeishuNotifier')
            ->assertSee('Enabled')
            ->assertSee('Feishu Webhook URL')
            ->assertSee('Sign secret (optional)')
            ->assertSee('v1.0.0');
    }

    public function test_settings_page_renders_labels_of_other_plugins(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/plugins/MailNotifier/settings')
            ->assertOk()
            ->assertSee('MailNotifier')
            ->assertSee('Admin emails (comma separated)')
            ->assertSee('Sender name');
    }

    public function test_unknown_plugin_aborts_404(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/plugins/DoesNotExist/settings')
            ->assertNotFound();
    }

    public function test_settings_page_saves_to_database(): void
    {
        Livewire::actingAs($this->admin)
            ->test(PluginSettingsPage::class, ['plugin' => 'FeishuNotifier'])
            ->set('data.webhook', 'https://open.feishu.cn/open-apis/bot/v2/hook/test-123')
            ->set('data.secret', 'my-secret')
            ->call('save')
            ->assertHasNoErrors();

        $stored = Setting::get('plugin.FeishuNotifier');
        $this->assertSame('https://open.feishu.cn/open-apis/bot/v2/hook/test-123', $stored['webhook']);
        $this->assertSame('my-secret', $stored['secret']);
        $this->assertArrayHasKey('enabled', $stored);
    }

    public function test_settings_page_respects_boolean_false(): void
    {
        Livewire::actingAs($this->admin)
            ->test(PluginSettingsPage::class, ['plugin' => 'WhatsAppChat'])
            ->set('data.enabled', false)
            ->set('data.phone', '8613900000000')
            ->call('save')
            ->assertHasNoErrors();

        $stored = Setting::get('plugin.WhatsAppChat');
        $this->assertFalse($stored['enabled']);

        // 显式 false 必须生效（不被当成"未设置"回落 config）
        $this->assertFalse(plugin_setting('whatsapp-chat', 'enabled', true));
    }

    public function test_settings_page_blocks_missing_required_fields(): void
    {
        // required 校验在 Store 内完成（不产生 Livewire 表单验证错误，以危险通知提示）
        Livewire::actingAs($this->admin)
            ->test(PluginSettingsPage::class, ['plugin' => 'MailNotifier'])
            ->set('data.admin_emails', '')
            ->call('save')
            ->assertHasNoErrors();

        // 必填缺失时不落库
        $stored = Setting::get('plugin.MailNotifier');
        $this->assertTrue($stored === null || ! array_key_exists('admin_emails', $stored));
    }
}
