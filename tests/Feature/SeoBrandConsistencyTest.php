<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SEO 品牌名与 meta description 纯文本化的回归。
 *
 * 线上实锤：后台把站点名改成 "Chinese supplier" 后，首页标题带新名，
 * 但产品/分类/文章页标题仍带 env 默认的 "BtoBcms"（config('site.name')），
 * JSON-LD 的 brand/author 同样是旧名——同站两个品牌名。
 * 另一处：分类 description 是 RichEditor，存的 HTML（含 `<p></p>` 空占位）
 * 被直接当 meta description 输出，Google 抓到的是标签而不是文案。
 */
class SeoBrandConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_model_pages_use_backend_site_name_not_env_default(): void
    {
        Setting::put('site.name', 'Chinese supplier');

        $this->get('/product/gate-valve-dn50')
            ->assertOk()
            ->assertSee('Chinese supplier')
            ->assertDontSee('BtoBcms');
    }

    public function test_static_pages_and_model_pages_share_one_site_name(): void
    {
        Setting::put('site.name', 'Chinese supplier');

        $home = (string) $this->get('/')->getContent();
        $product = (string) $this->get('/product/gate-valve-dn50')->getContent();

        // 首页与产品页必须出现同一个品牌名
        $this->assertStringContainsString('Chinese supplier', $home);
        $this->assertStringContainsString('Chinese supplier', $product);
        $this->assertStringNotContainsString('BtoBcms', $product);
    }

    public function test_json_ld_brand_uses_backend_site_name(): void
    {
        Setting::put('site.name', 'Chinese supplier');

        $html = (string) $this->get('/product/gate-valve-dn50')->getContent();

        $this->assertStringContainsString('"name":"Chinese supplier"', $html);
        $this->assertStringNotContainsString('"name":"BtoBcms"', $html);
    }

    public function test_category_meta_description_is_plain_text_not_html(): void
    {
        $category = Category::firstOrFail();
        $category->description = ['en' => '<p></p>'];
        $category->save();

        $html = (string) $this->get('/category/'.$category->slug)->getContent();

        // meta description 不能是编辑器空占位
        $this->assertStringNotContainsString('content="&lt;p&gt;&lt;/p&gt;"', $html);

        preg_match('/<meta name="description" content="([^"]*)"/', $html, $m);
        $this->assertNotEmpty($m, '页面应输出 meta description');
        $this->assertStringNotContainsString('<p>', $m[1]);
        $this->assertGreaterThan(50, mb_strlen($m[1]), 'description 应回退到模板文案而非空占位');
    }

    public function test_rich_text_description_is_stripped_to_plain_text(): void
    {
        $category = Category::firstOrFail();
        $category->description = ['en' => '<p>Industrial <b>gate valves</b> for water systems.</p>'];
        $category->save();

        $html = (string) $this->get('/category/'.$category->slug)->getContent();

        preg_match('/<meta name="description" content="([^"]*)"/', $html, $m);
        $this->assertNotEmpty($m);
        $this->assertStringContainsString('Industrial gate valves for water systems.', $m[1]);
    }

    public function test_less_than_signs_in_plain_text_survive(): void
    {
        $product = Product::firstOrFail();
        $product->summary = ['en' => 'MOQ < 100 pcs, lead <5 days'];
        $product->save();

        $html = (string) $this->get('/product/'.$product->slug)->getContent();

        preg_match('/<meta name="description" content="([^"]*)"/', $html, $m);
        $this->assertNotEmpty($m);
        // Blade 会转义 < 为 &lt;，比对前先反转义
        $decoded = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $this->assertStringContainsString('MOQ < 100 pcs', $decoded);
        $this->assertStringContainsString('lead <5 days', $decoded);
    }
}
