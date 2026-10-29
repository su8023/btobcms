<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Modules\TrustBuilder\Models\TrustCase;
use Modules\TrustBuilder\Models\TrustCertification;
use Tests\TestCase;

/**
 * TrustBuilder 信任背书插件：客户案例列表/详情（未发布 404）、JSON-LD、
 * 相关案例、全站信任条（渲染自检/案例页不重复渲染/无数据不输出）、
 * 多语言前缀路由与后台资源注册。
 */
class TrustBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /** 造一个客户案例（默认已发布，未指定语言字段回退 en） */
    private function makeCase(array $overrides = []): TrustCase
    {
        return TrustCase::create(array_merge([
            'slug' => 'case-'.uniqid(),
            'customer_name' => 'Acme GmbH',
            'industry' => 'Valves',
            'country' => 'DE',
            'title' => ['en' => 'Gate valve retrofit project'],
            'body' => ['en' => '<p>Premium valve delivery details</p>'],
            'cover' => null,
            'images' => null,
            'published' => true,
            'sort' => 0,
        ], $overrides));
    }

    /** 造一张认证证书 */
    private function makeCert(array $overrides = []): TrustCertification
    {
        return TrustCertification::create(array_merge([
            'name' => ['en' => 'ISO 9001:2015'],
            'issuer' => 'TUV Rheinland',
            'cert_no' => 'CERT-'.uniqid(),
            'issued_at' => '2024-01-01',
            'expires_at' => '2027-01-01',
            'image' => null,
            'sort' => 0,
        ], $overrides));
    }

    /** 列表页 200 且只含已发布案例 */
    public function test_cases_index_lists_only_published(): void
    {
        $published = $this->makeCase(['title' => ['en' => 'Published Case Alpha']]);
        $this->makeCase(['slug' => 'case-unpub', 'title' => ['en' => 'Unpublished Case Beta'], 'published' => false]);

        $this->get('/case-studies')
            ->assertOk()
            ->assertSee('Published Case Alpha', false)
            ->assertSee('Acme GmbH', false)
            ->assertDontSee('Unpublished Case Beta', false)
            // 详情链接指向已发布案例
            ->assertSee('/case-studies/'.$published->slug, false)
            ->assertDontSee('/case-studies/case-unpub"', false);

        // 列表页确认渲染卡片链接
        $this->get('/case-studies')->assertOk();
    }

    /** 未发布/不存在案例详情 404；已发布 200 */
    public function test_unpublished_case_detail_returns_404(): void
    {
        $published = $this->makeCase();
        $unpublished = $this->makeCase(['slug' => 'case-unpub-detail', 'published' => false]);

        $this->get('/case-studies/'.$published->slug)->assertOk();
        $this->get('/case-studies/'.$unpublished->slug)->assertNotFound();
        $this->get('/case-studies/nope-404')->assertNotFound();
    }

    /** 详情页渲染正文、meta 与 JSON-LD（BreadcrumbList + WebPage） */
    public function test_case_detail_renders_body_meta_and_jsonld(): void
    {
        $case = $this->makeCase();

        $this->get('/case-studies/'.$case->slug)
            ->assertOk()
            // 正文 {!! !!} 原样输出
            ->assertSee('Premium valve delivery details', false)
            // meta（customer/industry/country）
            ->assertSee('Acme GmbH', false)
            ->assertSee('Valves', false)
            ->assertSee('DE', false)
            // JSON-LD：两个图（BreadcrumbList + WebPage 含案例名）
            ->assertSee('"@type":"BreadcrumbList"', false)
            ->assertSee('"@type":"WebPage"', false)
            ->assertSee('Gate valve retrofit project', false)
            // CTA 指向联系页
            ->assertSee('href="'.localized_route('contact').'"', false);
    }

    /** 相关案例区块：同行业已发布（排除自身/其他行业/未发布），最多 3 条 */
    public function test_related_cases_shown_same_industry_only(): void
    {
        $main = $this->makeCase(['industry' => 'Valves']);
        $related = $this->makeCase(['slug' => 'case-related', 'title' => ['en' => 'Related Valve Case'], 'industry' => 'Valves']);
        $other = $this->makeCase(['slug' => 'case-other', 'title' => ['en' => 'Unrelated Pump Case'], 'industry' => 'Pumps']);
        $hidden = $this->makeCase(['slug' => 'case-hidden', 'title' => ['en' => 'Hidden Valve Case'], 'industry' => 'Valves', 'published' => false]);

        $this->get('/case-studies/'.$main->slug)
            ->assertOk()
            ->assertSee('Related case studies', false)
            ->assertSee('Related Valve Case', false)
            // 相关卡片链接正确
            ->assertSee('/case-studies/case-related', false)
            ->assertDontSee('Unrelated Pump Case', false)
            ->assertDontSee('Hidden Valve Case', false);

        // 引用值仅作类型提示防未使用告警
        unset($other, $hidden);
    }

    /** 信任条视图：证书名徽章（最多 6 个）+ 已发布案例计数链接 */
    public function test_trust_bar_renders_certificates_and_cases_count(): void
    {
        // 7 张证书：信任条最多展示 6 个徽章，第 7 张（sort 最大）被排除
        $this->makeCert(['name' => ['en' => 'Cert Alpha'], 'sort' => 1]);
        $this->makeCert(['name' => ['en' => 'Cert Beta'], 'sort' => 2]);
        $this->makeCert(['name' => ['en' => 'Cert Gamma'], 'sort' => 3]);
        $this->makeCert(['name' => ['en' => 'Cert Delta'], 'sort' => 4]);
        $this->makeCert(['name' => ['en' => 'Cert Epsilon'], 'sort' => 5]);
        $this->makeCert(['name' => ['en' => 'Cert Zeta'], 'sort' => 6]);
        $this->makeCert(['name' => ['en' => 'GHOST CERT OVER LIMIT'], 'sort' => 7]);
        // 3 个已发布 + 1 个未发布案例（未发布不计入）
        $this->makeCase(['sort' => 1]);
        $this->makeCase(['sort' => 2]);
        $this->makeCase(['sort' => 3]);
        $this->makeCase(['published' => false]);

        $html = view('trustbuilder::trust-bar')->render();

        $this->assertStringContainsString('Cert Alpha', $html);
        $this->assertStringContainsString('Cert Beta', $html);
        $this->assertStringNotContainsString('GHOST CERT OVER LIMIT', $html);
        // 「3 个客户案例」计数与列表页链接
        $this->assertStringContainsString('3 customer cases', $html);
        $this->assertStringContainsString('href="'.localized_route('cases.index').'"', $html);
    }

    /** 无证书且无案例时信任条整条不渲染 */
    public function test_trust_bar_hidden_without_data(): void
    {
        $this->assertSame('', trim(view('trustbuilder::trust-bar')->render()));
    }

    /** 信任条在其他页面渲染、在案例列表/详情页不重复渲染 */
    public function test_trust_bar_skipped_on_cases_pages(): void
    {
        $this->makeCert();
        $case = $this->makeCase();

        // 其他页面（about）正常渲染信任条
        $this->get('/about')
            ->assertOk()
            ->assertSee('<section class="trust-bar"', false);

        // 案例列表/详情页不重复渲染（视图内按当前路由名自检）
        $this->get('/case-studies')
            ->assertOk()
            ->assertDontSee('<section class="trust-bar"', false);

        $this->get('/case-studies/'.$case->slug)
            ->assertOk()
            ->assertDontSee('<section class="trust-bar"', false);
    }

    /** /es/ 语言前缀路由可用，localized_route 生成带前缀的详情链接 */
    public function test_es_prefix_route_and_localized_route(): void
    {
        $case = $this->makeCase(['title' => ['en' => 'Valve Retrofit', 'es' => 'Retrolado de válvulas']]);

        // es 列表页 200，展示 es 语言标题（spatie Translatable）
        $this->get('/es/case-studies')
            ->assertOk()
            ->assertSee('Retrolado de válvulas', false);

        // es 详情页 200，卡片/面包屑链接带 /es/ 前缀
        $this->get('/es/case-studies/'.$case->slug)
            ->assertOk()
            ->assertSee('/es/case-studies/'.$case->slug, false);

        // localized_route 解析 es.cases.show 命名路由
        $this->assertSame(
            url('/es/case-studies/'.$case->slug),
            localized_route('cases.show', ['slug' => $case->slug], 'es'),
        );
    }

    /** 后台 Filament 资源已注册（/admin/cases、/admin/certifications 出现在 route:list） */
    public function test_admin_resources_are_registered(): void
    {
        Artisan::call('route:list');
        $output = Artisan::output();

        $this->assertStringContainsString('admin/cases', $output);
        $this->assertStringContainsString('admin/certifications', $output);
    }

    /** 列表按 sort 升序展示 */
    public function test_cases_index_sorted_by_sort_column(): void
    {
        $late = $this->makeCase(['title' => ['en' => 'Case Sort Ten'], 'sort' => 10]);
        $early = $this->makeCase(['title' => ['en' => 'Case Sort Zero'], 'sort' => 0]);

        $html = $this->get('/case-studies')->assertOk()->getContent();

        $this->assertTrue(
            mb_strpos($html, 'Case Sort Zero') < mb_strpos($html, 'Case Sort Ten'),
            'sort=0 的案例应排在 sort=10 之前',
        );
    }
}
