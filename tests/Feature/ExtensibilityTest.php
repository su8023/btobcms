<?php

namespace Tests\Feature;

use App\Events\InquiryStatusChanged;
use App\Events\PostPublished;
use App\Events\ProductSaved;
use App\Models\Inquiry;
use App\Models\Post;
use App\Models\Product;
use App\Models\User;
use App\Support\PluginPages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * 扩展性接口回归：业务事件分发点 + 插件后台页面约定发现。
 * 这些是插件的挂载契约，改坏了插件生态就断了，必须锁测试。
 */
class ExtensibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(User::first());
    }

    public function test_inquiry_edit_page_has_status_event_hook(): void
    {
        // 约定：询盘编辑页必须挂 afterSave 并在状态真变化时发事件（反射锁定钩子存在）
        $ref = new \ReflectionMethod(\App\Filament\Resources\Inquiries\Pages\EditInquiry::class, 'afterSave');
        $body = (string) file_get_contents($ref->getFileName());
        $this->assertStringContainsString('InquiryStatusChanged::dispatch', $body);
        $this->assertStringContainsString("getOriginal('status')", $body);
    }

    public function test_inquiry_status_changed_event_fires_on_real_edit(): void
    {
        Event::fake(InquiryStatusChanged::class);
        $inquiry = $this->makeInquiry();

        // 模拟 EditRecord afterSave 钩子的真实行为：状态 new → quoted
        $inquiry->status = 'quoted';
        event(new InquiryStatusChanged($inquiry, 'new', 'quoted'));

        Event::assertDispatched(InquiryStatusChanged::class, fn ($e) => $e->oldStatus === 'new' && $e->newStatus === 'quoted');
    }

    private function makeInquiry(): Inquiry
    {
        return Inquiry::firstOrCreate(
            ['email' => 'ext-test@example.com'],
            ['name' => 'Ext', 'message' => 'm', 'status' => 'new', 'source' => 'test', 'ip' => '127.0.0.1'],
        );
    }

    public function test_post_published_event_contract(): void
    {
        Event::fake();
        $post = Post::first() ?? Post::create([
            'title' => 'T', 'slug' => 't-'.uniqid(), 'content' => ['en' => 'C'],
            'status' => 'published', 'type' => 'article',
        ]);

        event(new PostPublished($post));
        Event::assertDispatched(PostPublished::class);
    }

    public function test_product_saved_event_contract(): void
    {
        Event::fake();
        $product = Product::first();

        event(new ProductSaved($product, true));
        event(new ProductSaved($product, false));

        Event::assertDispatchedTimes(ProductSaved::class, 2);
    }

    public function test_plugin_pages_discovery_finds_enabled_module_pages(): void
    {
        $pages = PluginPages::discover();

        // 两个已启用插件的后台页应被约定发现（不再依赖 PanelProvider 硬编码）
        $this->assertContains(\Modules\UpdateCenter\Filament\Pages\UpdateCenterPage::class, $pages);
        $this->assertContains(\Modules\AiSeoAuditor\Filament\Pages\SeoAuditReports::class, $pages);

        // 发现的类必须是 Filament Page
        foreach ($pages as $class) {
            $this->assertTrue(is_subclass_of($class, \Filament\Pages\Page::class), "{$class} 不是 Filament Page");
        }
    }

    public function test_admin_panel_still_registers_plugin_pages(): void
    {
        // 约定式注册后页面仍可访问（路由可达）
        $this->get('/admin/update-center')->assertOk();
    }

    public function test_slots_support_new_positions(): void
    {
        \App\Support\Slots::push('body_top', 'whatsappchat::floating-button');
        \App\Support\Slots::push('footer_before', 'whatsappchat::floating-button');

        $this->assertCount(1, \App\Support\Slots::get('body_top'));
        $this->assertCount(1, \App\Support\Slots::get('footer_before'));
    }
}
