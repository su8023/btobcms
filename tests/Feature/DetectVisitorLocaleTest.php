<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * 访客语言自动检测：浏览器语言 / IP 地理 → 302 到对应语言页。
 * 优先级：已带前缀 > 会话已检测 > 爬虫不跳 > 浏览器语言 > IP 国家 > 默认 en。
 */
class DetectVisitorLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        // 本中间件面向 CDN 前置的部署场景（XFF / CF-IPCountry），
        // 生产默认不信任任何代理；测试里模拟的正是"流量经过可信 CDN"，
        // 因此在此声明信任代理，让 $request->ip() 采信测试注入的 XFF。
        // 仅影响本测试类，不改变全局测试环境与其他用例的语义。
        config(['trustedproxy.proxies' => '*']);

        // 任何未被 fake 的外部请求直接报错，确保检测路径不产生意外外呼
        Http::preventStrayRequests();
    }

    public function test_spanish_browser_is_redirected_to_spanish_home(): void
    {
        $this->get('/', ['Accept-Language' => 'es-MX,es;q=0.9,en;q=0.5'])
            ->assertStatus(302)
            ->assertRedirect('/es/')
            ->assertHeader('Vary', 'Accept-Language');
    }

    public function test_session_remembered_locale_is_not_redirected_again(): void
    {
        $this->withSession(['locale_detected' => 'es'])
            ->get('/', ['Accept-Language' => 'es'])
            ->assertOk();
    }

    public function test_googlebot_is_never_redirected(): void
    {
        $this->withHeaders(['X-Forwarded-For' => '8.8.8.8'])
            ->withServerVariables(['HTTP_USER_AGENT' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'])
            ->get('/')
            ->assertOk();
    }

    public function test_cf_ip_country_header_redirects_without_outbound_lookup(): void
    {
        // Symfony 测试客户端默认注入 Accept-Language: en-us（无法真正省略），
        // 用站点不支持的语言 zz 强制跳过浏览器语言步骤，落到 IP 分支。
        // CF 头优先：不应触发 ip-api 查询（preventStrayRequests 会拦截任何外呼）。
        $this->withHeaders([
            'X-Forwarded-For' => '8.8.8.8',
            'CF-IPCountry' => 'BR',
            'Accept-Language' => 'zz',
        ])->get('/')
            ->assertStatus(302)
            ->assertRedirect('/pt/');
    }

    public function test_ip_geo_lookup_redirects_and_caches(): void
    {
        Http::fake([
            'ip-api.com/*' => Http::response(['status' => 'success', 'countryCode' => 'DE'], 200),
        ]);

        $this->withHeaders([
            'X-Forwarded-For' => '85.214.132.117',
            'Accept-Language' => 'zz',
        ])->get('/')
            ->assertStatus(302)
            ->assertRedirect('/de/');

        // 新会话再访问深链：命中 geo 缓存仍跳 /de/，且不再外呼
        // （preventStrayRequests 会拦截任何第二次 ip-api 请求）
        session()->flush();
        $this->withHeaders([
            'X-Forwarded-For' => '85.214.132.117',
            'Accept-Language' => 'zz',
        ])->get('/about')
            ->assertRedirect('/de/about');
    }

    public function test_geo_lookup_failure_falls_back_to_english(): void
    {
        Http::fake([
            'ip-api.com/*' => Http::response(['status' => 'fail'], 200),
        ]);

        $this->withHeaders([
            'X-Forwarded-For' => '1.2.3.4',
            'Accept-Language' => 'zz',
        ])->get('/')
            ->assertOk();
    }

    public function test_private_ip_skips_geo_lookup(): void
    {
        // 内网 IP（127.0.0.1）不做地理查询，直接落默认语言；
        // 若误触外呼会被 preventStrayRequests 拦下报错
        $this->withHeaders(['Accept-Language' => 'zz'])
            ->get('/')
            ->assertOk();
    }

    public function test_direct_prefixed_visit_is_never_redirected(): void
    {
        $this->withHeaders(['X-Forwarded-For' => '8.8.8.8'])
            ->get('/es/')
            ->assertOk();
    }

    public function test_deep_link_path_and_query_are_preserved(): void
    {
        $this->get('/about?ref=ad', ['Accept-Language' => 'fr'])
            ->assertStatus(302)
            ->assertRedirect('/fr/about?ref=ad');
    }

    public function test_unsupported_browser_language_falls_through_to_geo(): void
    {
        // 浏览器只声明 ja/jp（站点不支持）→ 继续走 IP 检测
        Http::fake([
            'ip-api.com/*' => Http::response(['status' => 'success', 'countryCode' => 'MX'], 200),
        ]);

        $this->withHeaders([
            'X-Forwarded-For' => '8.8.8.8',
            'Accept-Language' => 'ja-JP,ja;q=0.9',
        ])->get('/')
            ->assertStatus(302)
            ->assertRedirect('/es/');
    }
}
