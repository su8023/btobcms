<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::firstOrFail();
    }

    public function test_admin_requires_login(): void
    {
        $this->get('/admin')->assertRedirect();
    }

    public function test_admin_dashboard_renders(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin')
            ->assertOk();
    }

    /** 回归：资料/改密码页必须可用（此前未注册，后台没有改密码入口） */
    public function test_admin_profile_page_renders(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/profile')
            ->assertOk();
    }

    public function test_all_resource_index_pages_render(): void
    {
        $this->actingAs($this->admin);

        foreach ([
            '/admin/products',
            '/admin/products/create',
            '/admin/categories',
            '/admin/categories/create',
            '/admin/posts',
            '/admin/posts/create',
            '/admin/inquiries',
            '/admin/inquiries/create',
            '/admin/attr-sets',
            '/admin/attributes',
            '/admin/attributes/create',
            '/admin/redirects',
            // 模块页面（曾因 Filament v5 命名空间迁移遗漏 / @php() 编译坏而 500，列为常驻回归）
            '/admin/lead-radars',
            '/admin/link-terms',
            '/admin/seo-audit-reports',
            '/admin/ai-traffic',
            '/admin/gsc-pulse',
            '/admin/trans-coverage',
            '/admin/image-forge',
            '/admin/magnets',
            '/admin/cases',
            '/admin/certifications',
            '/admin/site-settings',
            '/admin/theme-center',
            '/admin/plugin-center',
            '/admin/update-center',
            '/admin/ai-studio',
        ] as $url) {
            $this->get($url)->assertOk($url);
        }
    }

    public function test_admin_edit_product_page_renders(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/products/1/edit')
            ->assertOk();
    }

    public function test_admin_edit_inquiry_page_renders_with_followups(): void
    {
        $inquiry = \App\Models\Inquiry::create([
            'name' => 'Admin Test',
            'email' => 'admin-test@example.com',
            'message' => 'Please quote.',
        ]);

        // 断言用语言无关的值（后台支持中英切换，不固定断言文案）
        $this->actingAs($this->admin)
            ->get("/admin/inquiries/{$inquiry->id}/edit")
            ->assertOk()
            ->assertSee('admin-test@example.com');
    }

    public function test_admin_locale_switches_to_chinese_and_back(): void
    {
        $this->actingAs($this->admin);

        // 切到中文
        $this->get('/admin/locale/zh_CN')->assertRedirect();
        $this->assertSame('zh_CN', $this->admin->fresh()->locale);

        $response = $this->get('/admin');
        $response->assertOk();

        // 侧边栏出现中文导航（我们的翻译 + Filament 自带 zh_CN）
        $this->assertStringContainsString('产品', $response->getContent());

        // 切回英文
        $this->get('/admin/locale/en')->assertRedirect();
        $this->assertSame('en', $this->admin->fresh()->locale);

        $this->get('/admin')->assertOk()->assertSee('Products');
    }

    public function test_admin_locale_rejects_unknown(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/locale/fr')
            ->assertStatus(404);
    }
}
