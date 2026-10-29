<?php

namespace Tests\Feature;

use App\Events\InquiryCreated;
use App\Models\Inquiry;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\MailNotifier\Mail\InquiryConfirmation;
use Modules\MailNotifier\Mail\InquiryNotification;
use Tests\TestCase;

/**
 * 五个插件的端到端功能测试：每个插件在真实事件链路中运行一次。
 */
class PluginFunctionalityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_feishu_notifier_sends_interactive_card_to_webhook(): void
    {
        // 在插件中心（数据库设置）保存 webhook —— 插件必须读取数据库而非仅 .env
        Setting::put('plugin.FeishuNotifier', [
            'enabled' => true,
            'webhook' => 'https://open.feishu.cn/open-apis/bot/v2/hook/test-hook',
            'secret' => '',
        ]);

        Http::fake(['*' => Http::response(['code' => 0], 200)]);

        $inquiry = Inquiry::create([
            'name' => 'Feishu Test',
            'email' => 'feishu@example.com',
            'message' => 'Please quote gate valves.',
        ]);
        InquiryCreated::dispatch($inquiry);

        Http::assertSent(function ($request) use ($inquiry) {
            return str_contains($request->url(), 'test-hook')
                && $request['msg_type'] === 'interactive'
                && str_contains(json_encode($request['card'], JSON_UNESCAPED_UNICODE), $inquiry->email)
                && str_contains(json_encode($request['card'], JSON_UNESCAPED_UNICODE), '新询盘提醒');
        });
    }

    public function test_mail_notifier_sends_buyer_and_admin_emails(): void
    {
        Setting::put('plugin.MailNotifier', [
            'enabled' => true,
            'admin_emails' => 'db-admin@example.test, boss@example.test',
            'sender_name' => 'Verify Bot',
        ]);

        Mail::fake();

        $inquiry = Inquiry::create([
            'name' => 'Mail Test',
            'email' => 'buyer@example.test',
            'message' => 'Please quote.',
        ]);
        InquiryCreated::dispatch($inquiry);

        Mail::assertSent(InquiryConfirmation::class, fn ($m) => $m->hasTo('buyer@example.test'));
        Mail::assertSent(InquiryNotification::class, fn ($m) => $m->hasTo('db-admin@example.test') && $m->hasTo('boss@example.test'));
    }

    public function test_mail_notifier_skips_when_disabled(): void
    {
        Setting::put('plugin.MailNotifier', ['enabled' => false, 'admin_emails' => 'x@example.test']);

        Mail::fake();

        $inquiry = Inquiry::create(['name' => 'X', 'email' => 'a@b.c', 'message' => 'x']);
        InquiryCreated::dispatch($inquiry);

        Mail::assertNothingSent();
    }

    public function test_whatsapp_chat_injects_widget_with_database_phone(): void
    {
        Setting::put('plugin.WhatsAppChat', [
            'enabled' => true,
            'phone' => '8613900000000',
            'message' => 'Hello from test',
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('wa.me/8613900000000', false)
            ->assertSee('Hello+from+test', false);
    }

    public function test_whatsapp_chat_hides_widget_when_disabled(): void
    {
        // 停用开关（enabled=false）是隐藏挂件的正确方式；
        // 仅清空 phone 时回落 .env 配置（设计语义：空值=未覆盖）
        Setting::put('plugin.WhatsAppChat', ['enabled' => false, 'phone' => '8613900000000']);

        $this->get('/')->assertOk()->assertDontSee('wa.me', false);
    }

    public function test_ai_seo_auditor_audits_pages_and_writes_report(): void
    {
        // 模拟站点：sitemap 含 2 页，页面要素齐全
        $page = '<html><head><title>A Good Page Title Here</title><meta name="description" content="A properly long description that passes the minimum length requirement for the audit."><link rel="canonical" href="https://fake.test/a"></head><body><h1>Heading</h1></body></html>';
        Http::fake([
            'https://fake.test/sitemap.xml' => Http::response('<?xml version="1.0"?><urlset><url><loc>https://fake.test/a</loc></url><url><loc>https://fake.test/b</loc></url></urlset>', 200),
            'https://fake.test/*' => Http::response($page, 200),
        ]);

        Setting::put('plugin.AiSeoAuditor', ['enabled' => true, 'base_url' => 'https://fake.test', 'limit' => 10]);

        $exit = Artisan::call('seo:audit');

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('巡检完成', Artisan::output());

        // 报告落盘
        $reports = glob(storage_path('app/seo-audits/audit-*.json'));
        $this->assertNotEmpty($reports);
        $latest = end($reports);
        $data = json_decode((string) file_get_contents($latest), true);
        $this->assertSame(2, $data['checked']);
        $this->assertSame([], $data['issues']);

        // 清理测试报告
        foreach (glob(storage_path('app/seo-audits/audit-*.json')) as $f) {
            @unlink($f);
        }
    }

    public function test_ai_content_writer_fails_gracefully_without_api_key(): void
    {
        config(['ai.providers.deepseek.key' => null]);

        $exit = Artisan::call('ai:article', ['topic' => 'test topic']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('AI 服务还没配置好', Artisan::output());

        // 未落库任何文章
        $this->assertSame(0, \App\Models\Post::count());
    }

    public function test_feishu_push_failure_creates_admin_alert(): void
    {
        Setting::put('plugin.FeishuNotifier', [
            'enabled' => true,
            'webhook' => 'https://open.feishu.cn/open-apis/bot/v2/hook/fail-hook',
            'secret' => '',
        ]);

        // 飞书端返回错误码 → 推送失败 → 后台铃铛出现告警（不再静默丢失）
        Http::fake(['*' => Http::response(['code' => 19021, 'msg' => 'sign error'], 200)]);

        $inquiry = Inquiry::create([
            'name' => 'Alert Test',
            'email' => 'alert@example.com',
            'message' => 'x',
        ]);
        InquiryCreated::dispatch($inquiry);

        $this->assertDatabaseCount('notifications', 1);
        $notification = \Illuminate\Notifications\DatabaseNotification::first();
        $this->assertStringContainsString('飞书推送失败', $notification->data['title']);
        $this->assertStringContainsString('alert@example.com', $notification->data['body']);
    }

    public function test_inquiry_records_ip_and_user_agent(): void
    {
        $this->postJson('/api/inquiries', [
            'name' => 'IP Test',
            'email' => 'ip-test@example.com',
            'message' => 'IP capture check',
        ])->assertCreated();

        $inquiry = \App\Models\Inquiry::where('email', 'ip-test@example.com')->first();
        $this->assertNotNull($inquiry->ip);
        $this->assertStringContainsString('Symfony', $inquiry->user_agent);
    }

    public function test_plugins_verify_command_reports_all_plugins(): void
    {
        Setting::put('plugin.FeishuNotifier', ['enabled' => true, 'webhook' => 'https://open.feishu.cn/open-apis/bot/v2/hook/x']);
        Setting::put('plugin.MailNotifier', ['enabled' => true, 'admin_emails' => 'a@b.c']);
        Setting::put('plugin.WhatsAppChat', ['enabled' => true, 'phone' => '8613900000000']);

        $exit = Artisan::call('plugins:verify');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        foreach (['FeishuNotifier', 'MailNotifier', 'WhatsAppChat', 'AiSeoAuditor', 'AiContentWriter'] as $name) {
            $this->assertStringContainsString($name, $output);
        }
        $this->assertStringContainsString('插件可用性', $output);
    }

    public function test_plugin_center_shows_health_check_action(): void
    {
        $admin = User::firstOrFail();

        Livewire::actingAs($admin)
            ->test(\App\Filament\Pages\PluginCenter::class)
            ->callAction('runCheck')
            ->assertNotified('Plugin health check finished');
    }
}
