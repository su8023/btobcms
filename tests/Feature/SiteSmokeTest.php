<?php

namespace Tests\Feature;

use App\Models\Redirect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_home_page_renders_in_default_locale(): void
    {
        $response = $this->get('/');

        $response->assertOk()
            ->assertSee('Reliable Manufacturer')
            ->assertSee('hreflang="es"', false)
            ->assertSee('rel="canonical"', false)
            ->assertSee('application/ld+json', false)
            // 专业主页新结构：hero 统计条 + 信任特性区
            ->assertSee('hero-stats', false)
            ->assertSee('Years in export')
            ->assertSee('Factory Direct')
            // 调研结论落地：认证前置 / 首屏询盘表单卡 / 保障条 / 行业入口卡
            ->assertSee('hero-cert', false)
            ->assertSee('ISO 9001:2015')
            ->assertSee('hero-card', false)
            ->assertSee('data-inquiry-form', false)
            ->assertSee('guarantee', false)
            ->assertSee('Inspection report before shipment')
            ->assertSee('ind-card', false)
            ->assertSee('Industries we serve')
            ->assertSee('/industries/water-plumbing', false);

        $content = $response->getContent();
        $this->assertStringContainsString('"@type":"WebSite"', $content, 'WebSite JSON-LD (GEO sitelinks searchbox)');
        $this->assertStringContainsString('SearchAction', $content);
        $this->assertStringContainsString('"@type":"ItemList"', $content, 'ItemList JSON-LD (GEO)');
    }

    public function test_llms_txt_serves_site_brief_for_ai_engines(): void
    {
        $this->get('/llms.txt')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/markdown; charset=UTF-8')
            ->assertSee('# BtoBcms')
            ->assertSee('## Industries we serve')
            ->assertSee('Water Treatment & Plumbing', false)
            ->assertSee('/industries/water-plumbing', false)
            ->assertSee('## Product categories')
            ->assertSee('Gate Valve DN50');
    }

    public function test_robots_explicitly_allows_ai_crawlers(): void
    {
        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee('Sitemap:')
            ->assertSee('User-agent: GPTBot')
            ->assertSee('User-agent: ClaudeBot')
            ->assertSee('User-agent: PerplexityBot');
    }

    public function test_about_page_has_faq_schema(): void
    {
        $this->get('/about')
            ->assertOk()
            ->assertSee('Frequently Asked Questions')
            ->assertSee('faq-item', false)
            ->assertSee('"@type":"FAQPage"', false)
            ->assertSee('minimum order quantity');
    }

    public function test_industries_index_lists_all_industry_pages(): void
    {
        $count = collect(config('industries'))->count();

        $this->get('/industries')
            ->assertOk()
            ->assertSee('Industries', false)
            ->assertSee('hreflang="es"', false);

        $content = $this->get('/industries')->getContent();
        $this->assertSame($count, substr_count($content, 'industries/'), "行业卡片数应等于 config 定义数（{$count}）");
    }

    public function test_industry_landing_page_renders_service_seo(): void
    {
        $this->get('/industries/water-plumbing')
            ->assertOk()
            ->assertSee('Water Treatment & Plumbing')
            ->assertSee('Verified factory sourcing')
            ->assertSee('data-inquiry-form', false)
            ->assertSee('"@type":"Service"', false)
            ->assertSee('rel="canonical"', false);

        $this->get('/industries/does-not-exist')->assertNotFound();

        // 本地化变体 + 语言键不回退
        $this->get('/es/industries/water-plumbing')->assertOk()->assertSee('Tratamiento');
    }

    public function test_services_page_renders_service_cards(): void
    {
        $this->get('/services')
            ->assertOk()
            ->assertSee('Services', false)
            ->assertSee('data-inquiry-form', false);

        $content = $this->get('/services')->getContent();
        $this->assertSame(4, substr_count($content, 'service-card'), '服务页应有 4 张服务卡');
    }

    public function test_home_page_in_spanish(): void
    {
        $this->get('/es')->assertOk()->assertSee('Fabricante');
    }

    public function test_arabic_uses_rtl(): void
    {
        $this->get('/ar')->assertOk()->assertSee('dir="rtl"', false);
    }

    public function test_russian_home(): void
    {
        $this->get('/ru')->assertOk()->assertSee('Надёжный');
    }

    public function test_product_page(): void
    {
        $this->get('/product/gate-valve-dn50')
            ->assertOk()
            ->assertSee('Gate Valve DN50')
            ->assertSee('application/ld+json', false)
            ->assertSee('hreflang="ar"', false)
            ->assertSee('rel="canonical"', false);
    }

    public function test_category_page_shows_products(): void
    {
        $this->get('/category/pumps')
            ->assertOk()
            ->assertSee('Centrifugal Water Pump');
    }

    public function test_category_facet_filters_products(): void
    {
        $this->get('/category/pumps?f[voltage]=380V')
            ->assertOk()
            ->assertSee('Centrifugal Water Pump');

        $this->get('/category/pumps?f[voltage]=110V')
            ->assertOk()
            ->assertDontSee('Centrifugal Water Pump');
    }

    public function test_products_index_with_search(): void
    {
        $this->get('/products?q=gate')
            ->assertOk()
            ->assertSee('Gate Valve DN50');
    }

    public function test_sitemap_index_and_sections(): void
    {
        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee('sitemap-industries.xml', false)
            ->assertSee('sitemapindex', false);

        $this->get('/sitemap-products.xml')
            ->assertOk()
            ->assertSee('gate-valve-dn50');

        $this->get('/sitemap-pages.xml')
            ->assertOk()
            ->assertSee('/es')
            // 纯线索站核心页必须进 sitemap（此前硬编码清单遗漏）
            ->assertSee('/services<', false)
            ->assertSee('/industries<', false);

        $count = collect(config('industries'))->count();
        $content = (string) $this->get('/sitemap-industries.xml')->getContent();
        $this->assertSame($count, substr_count($content, '<url>'), '行业分片应收录全部行业落地页');
        $this->assertStringContainsString('/industries/water-plumbing', $content);
        $this->assertStringContainsString('/pt/industries/tools-hardware', $content);
        $this->assertSame($count * 8, substr_count($content, 'hreflang'), '每个行业 URL 应带 8 语言 hreflang');
    }

    public function test_robots_txt(): void
    {
        $this->get('/robots.txt')
            ->assertOk()
            ->assertSee('Sitemap:');
    }

    public function test_basket_page_renders(): void
    {
        $this->get('/inquiry-basket')
            ->assertOk()
            ->assertSee('Inquiry Basket')
            ->assertSee('noindex', false);

        $this->get('/es/inquiry-basket')->assertOk()->assertSee('Cesta de consultas');
    }

    public function test_basket_api_returns_products(): void
    {
        $this->get('/api/basket/products?ids=1,3')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonFragment(['slug' => 'gate-valve-dn50'])
            ->assertJsonFragment(['slug' => 'galvanized-steel-pipe-dn100']);
    }

    public function test_basket_inquiry_submits_multiple_products(): void
    {
        $response = $this->postJson('/api/inquiries', [
            'name' => 'RFQ Buyer',
            'email' => 'rfq@example.com',
            'message' => 'Please quote all items in my basket.',
            'product_ids' => [1, 2, 3],
            'source' => 'basket',
        ]);

        $response->assertCreated();

        $inquiry = \App\Models\Inquiry::where('email', 'rfq@example.com')->first();
        $this->assertCount(3, $inquiry->items);
        $this->assertSame('basket', $inquiry->source);
    }

    public function test_inquiry_api_creates_inquiry_with_attribution(): void
    {
        $response = $this->postJson('/api/inquiries', [
            'name' => 'Test Buyer',
            'email' => 'buyer@example.com',
            'message' => 'Need 500 pcs of DN50 gate valves, CIF Los Angeles.',
            'product_ids' => [1],
            'utm' => ['source' => 'google', 'medium' => 'cpc'],
            'landing_url' => 'https://example.com/product/gate-valve-dn50',
            'source' => 'product',
        ]);

        $response->assertCreated();

        $this->assertDatabaseHas('inquiries', [
            'email' => 'buyer@example.com',
            'status' => 'new',
        ]);

        $inquiry = \App\Models\Inquiry::where('email', 'buyer@example.com')->first();
        $this->assertSame('google', $inquiry->utm['source'] ?? null);
        $this->assertNull($inquiry->assignee_id);
        $this->assertCount(1, $inquiry->items);
    }

    public function test_inquiry_honeypot_marks_spam(): void
    {
        $this->postJson('/api/inquiries', [
            'name' => 'Spam Bot',
            'email' => 'bot@spam.com',
            'message' => 'buy now cheap seo links',
            'website' => 'http://spam.example',
        ])->assertCreated();

        $this->assertDatabaseHas('inquiries', [
            'email' => 'bot@spam.com',
            'status' => 'spam',
        ]);
    }

    public function test_redirect_rule_applies(): void
    {
        Redirect::create([
            'from_path' => '/old-url',
            'to_path' => '/products',
            'code' => 301,
        ]);

        $this->get('/old-url')->assertStatus(301)->assertRedirect('/products');
    }

    public function test_new_redirect_rule_takes_effect_after_cached_miss(): void
    {
        // 先请求一次未命中（回归：miss 曾被缓存 1 小时导致新增规则不生效）
        $this->get('/another-old-url')->assertNotFound();

        Redirect::create([
            'from_path' => '/another-old-url',
            'to_path' => '/products',
            'code' => 301,
        ]);

        $this->get('/another-old-url')->assertStatus(301)->assertRedirect('/products');
    }
}
