<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Support\PluginSettingsStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\FeishuNotifier\Support\FeishuClient;
use Tests\TestCase;

class PluginSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::firstOrFail();
    }

    public function test_settings_page_links_from_plugin_center(): void
    {
        // 内联面板已迁移至独立设置页：插件中心显示"设置"链接，指向 /admin/plugins/{name}/settings
        $this->actingAs($this->admin)
            ->get('/admin/plugin-center')
            ->assertOk()
            ->assertSee('/admin/plugins/FeishuNotifier/settings', false)
            ->assertSee('/admin/plugins/MailNotifier/settings', false)
            ->assertSee('/admin/plugins/WhatsAppChat/settings', false)
            ->assertSee('/admin/plugins/AiSeoAuditor/settings', false)
            ->assertSee('/admin/plugins/AiContentWriter/settings', false);
    }

    public function test_settings_labels_render_chinese_when_admin_locale_is_chinese(): void
    {
        $this->admin->update(['locale' => 'zh_CN']);

        $this->actingAs($this->admin)
            ->get('/admin/plugins/FeishuNotifier/settings')
            ->assertOk()
            ->assertSee('飞书机器人 Webhook 地址')
            ->assertSee('签名密钥（可选）')
            ->assertSee('已启用');

        $this->actingAs($this->admin)
            ->get('/admin/plugins/AiSeoAuditor/settings')
            ->assertOk()
            ->assertSee('每日自动巡检')
            ->assertSee('每日巡检时间（0-23 点，服务器时区）');
    }

    public function test_save_settings_persists_to_database(): void
    {
        $result = PluginSettingsStore::save('FeishuNotifier', [
            'enabled' => true,
            'webhook' => 'https://open.feishu.cn/open-apis/bot/v2/hook/test-123',
            'secret' => 'my-secret',
        ]);

        $this->assertTrue($result['ok']);

        $stored = Setting::get('plugin.FeishuNotifier');
        $this->assertSame('https://open.feishu.cn/open-apis/bot/v2/hook/test-123', $stored['webhook']);
        $this->assertSame('my-secret', $stored['secret']);
        $this->assertArrayHasKey('enabled', $stored);
    }

    public function test_plugin_setting_prefers_database_over_config(): void
    {
        config(['feishu-notifier.webhook' => 'https://from-env.example/hook']);

        // 未保存数据库设置 → 回落 config
        Setting::where('key', 'plugin.FeishuNotifier')->delete();
        $this->assertSame('https://from-env.example/hook', plugin_setting('feishu-notifier', 'webhook'));

        // 保存数据库设置 → 数据库优先
        Setting::put('plugin.FeishuNotifier', ['webhook' => 'https://from-db.example/hook']);
        $this->assertSame('https://from-db.example/hook', plugin_setting('feishu-notifier', 'webhook'));
    }

    public function test_feishu_client_uses_database_webhook(): void
    {
        config(['feishu-notifier.enabled' => true]);
        config(['feishu-notifier.webhook' => '']);

        // 只在数据库中配置 webhook（模拟插件中心保存）→ FeishuClient 认为已配置
        Setting::put('plugin.FeishuNotifier', [
            'enabled' => true,
            'webhook' => 'https://open.feishu.cn/open-apis/bot/v2/hook/from-db',
            'secret' => '',
        ]);

        $client = new \ReflectionClass(FeishuClient::class);
        $method = $client->getMethod('sendCard');
        $start = $method->getStartLine();
        $source = file_get_contents($client->getFileName());
        $body = substr($source, (int) $start);

        $this->assertStringContainsString("plugin_setting('feishu-notifier', 'webhook'", $body);
        $this->assertStringContainsString("plugin_setting('feishu-notifier', 'enabled'", $body);
    }

    public function test_save_settings_respects_boolean_false(): void
    {
        $result = PluginSettingsStore::save('WhatsAppChat', [
            'enabled' => false,
            'phone' => '8613900000000',
        ]);

        $this->assertTrue($result['ok']);

        $stored = Setting::get('plugin.WhatsAppChat');
        $this->assertFalse($stored['enabled']);

        // 显式 false 必须生效（不被当成"未设置"回落 config）
        $this->assertFalse(plugin_setting('whatsapp-chat', 'enabled', true));
    }

    public function test_save_settings_requires_required_fields(): void
    {
        $result = PluginSettingsStore::save('MailNotifier', [
            'admin_emails' => '',
        ]);

        // 必填缺失时拒绝并返回字段 label，不落库
        $this->assertFalse($result['ok']);
        $this->assertSame('Admin emails (comma separated)', $result['error']);

        $stored = Setting::get('plugin.MailNotifier');
        $this->assertTrue($stored === null || ! array_key_exists('admin_emails', $stored));
    }
}
