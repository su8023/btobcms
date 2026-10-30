<?php

namespace Tests\Feature;

use App\Filament\Pages\SiteSettings;
use App\Models\Inquiry;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminFeaturesTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::firstOrFail();
    }

    public function test_settings_page_renders(): void
    {
        $this->actingAs($this->admin)
            ->get('/admin/site-settings')
            ->assertOk()
            ->assertSee('Site Settings');
    }

    public function test_settings_save_and_take_effect_on_front_site(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(SiteSettings::class)
            ->set('data.site_name', 'Acme Trading Co')
            ->set('data.contact_email', 'sales@acme.test')
            ->set('data.home_title_en', 'Acme Custom Homepage Title')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Acme Trading Co', Setting::get('site.name'));
        $this->assertSame('sales@acme.test', Setting::get('site.contact.email'));
        $this->assertSame('Acme Custom Homepage Title', Setting::get('seo.home.title.en'));

        // 前台品牌名与首页标题使用设置值
        $this->get('/')
            ->assertOk()
            ->assertSee('Acme Trading Co')
            ->assertSee('Acme Custom Homepage Title');

        // 联系页显示新邮箱
        $this->get('/contact')->assertOk()->assertSee('sales@acme.test');

        // 首页 CTA 横幅也读取设置值（历史 bug：直接读 config 默认值 sales@example.com）
        $this->get('/')
            ->assertOk()
            ->assertSee('sales@acme.test')
            ->assertDontSee('sales@example.com');
    }

    public function test_inquiry_export_csv_downloads_with_rows(): void
    {
        Inquiry::create([
            'name' => 'Export Test',
            'email' => 'export@example.com',
            'message' => 'CSV export test inquiry',
        ]);

        $response = $this->actingAs($this->admin)->get('/admin/inquiries/export');

        $response->assertOk();

        $content = $response->streamedContent();
        $this->assertStringContainsString('email', $content);
        $this->assertStringContainsString('export@example.com', $content);
    }
}
