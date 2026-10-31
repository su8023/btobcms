<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Modules\GscPulse\Filament\Pages\GscConnectPage;
use Modules\GscPulse\Models\GscCredential;
use Modules\GscPulse\Models\GscDaily;
use Modules\GscPulse\Services\GscFetcher;
use Modules\GscPulse\Services\GscReport;
use RuntimeException;
use Tests\TestCase;

/**
 * GscPulse：GSC OAuth 连接（手写无 SDK）、数据回流（token 刷新+分页+幂等 upsert）、看板三榜单聚合。
 * OAuth 与 searchAnalytics 全部 Http::fake，不发真实网络请求。
 */
class GscPulseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->actingAs(User::first());

        // 站点属性默认取 app.url 补尾斜杠：统一为 https://www.aples.xyz/
        config(['app.url' => 'https://www.aples.xyz']);
    }

    /* ----------------------------------------------------------
     | 工具
     * --------------------------------------------------------- */

    /** 建一条测试凭据（默认 access_token 有效期 1 小时，不触发刷新） */
    private function makeCredential(array $overrides = []): GscCredential
    {
        return GscCredential::create(array_merge([
            'site_url' => 'https://www.aples.xyz/',
            'access_token' => 'at-1',
            'refresh_token' => 'rt-1',
            'token_expires_at' => now()->addHour(),
        ], $overrides));
    }

    /** 插入一条 gsc_daily 流水（自动计算 fingerprint） */
    private function addDaily(array $overrides = []): void
    {
        $attrs = array_merge([
            'site_url' => 'https://www.aples.xyz/',
            'date' => now()->subDays(3)->toDateString(),
            'page' => 'https://www.aples.xyz/blog/gate',
            'query' => 'gate valve supplier',
            'country' => 'us',
            'clicks' => 0,
            'impressions' => 0,
            'position' => null,
        ], $overrides);

        $attrs['fingerprint'] = GscDaily::makeFingerprint(
            $attrs['site_url'], $attrs['date'], $attrs['page'], $attrs['query'], $attrs['country']
        );

        GscDaily::create($attrs);
    }

    /** 构造一行 GSC searchAnalytics 响应行（keys 顺序 = date, page, query, country） */
    private function apiRow(string $date, string $page, string $query, string $country, int $clicks, int $impressions, float $position): array
    {
        return [
            'keys' => [$date, $page, $query, $country],
            'clicks' => $clicks,
            'impressions' => $impressions,
            'position' => $position,
        ];
    }

    /* ----------------------------------------------------------
     | 拉取服务
     * --------------------------------------------------------- */

    public function test_fetch_requires_connection(): void
    {
        $this->expectException(RuntimeException::class);

        app(GscFetcher::class)->fetch();
    }

    public function test_fetcher_refreshes_token_and_upserts_paginated_rows(): void
    {
        // 行数上限压到 2，制造「两页分页」场景
        config(['gsc-pulse.row_limit' => 2]);

        // token 已过期 → 应先刷新再查询
        $this->makeCredential(['access_token' => 'at-old', 'token_expires_at' => now()->subMinutes(5)]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'at-new', 'expires_in' => 3600]),
            'https://searchconsole.googleapis.com/*' => Http::sequence()
                // 第一页 2 行 == row_limit → 触发第二页
                ->push(['rows' => [
                    $this->apiRow(now()->subDays(3)->toDateString(), 'https://www.aples.xyz/blog/a', 'valve supplier', 'us', 10, 200, 5.5),
                    $this->apiRow(now()->subDays(3)->toDateString(), 'https://www.aples.xyz/blog/b', 'gate valve price', 'us', 3, 150, 8.2),
                ]])
                // 第二页 1 行 < row_limit → 结束
                ->push(['rows' => [
                    $this->apiRow(now()->subDays(4)->toDateString(), 'https://www.aples.xyz/es/blog/a', 'válvula de puerta', 'es', 1, 60, 12.0),
                ]])
                ->push(['rows' => []]), // 不应被消费
        ]);

        $count = app(GscFetcher::class)->fetch();

        $this->assertSame(3, $count);
        $this->assertSame(3, GscDaily::count());

        // token 已刷新并回存
        $this->assertSame('at-new', GscCredential::first()->access_token);
        Http::assertSent(fn ($req) => str_contains($req->url(), 'oauth2.googleapis.com'));

        // 分页确实发生：startRow=0 与 startRow=2 各请求一次
        Http::assertSent(
            fn ($req) => str_contains($req->url(), 'searchAnalytics/query') && $req->data()['startRow'] === 0
        );
        Http::assertSent(
            fn ($req) => str_contains($req->url(), 'searchAnalytics/query') && $req->data()['startRow'] === 2
        );

        // 字段落库正确（维度顺序 page/query/country）
        $first = GscDaily::where('page', 'https://www.aples.xyz/blog/a')->first();
        $this->assertSame('valve supplier', $first->query);
        $this->assertSame('us', $first->country);
        $this->assertSame(10, $first->clicks);
        $this->assertSame(200, $first->impressions);
        $this->assertSame(5.5, (float) $first->position);
    }

    public function test_fetch_is_idempotent_on_rerun(): void
    {
        $this->makeCredential(); // token 有效，不触发刷新

        // 两次拉取共用一个序列：第 1 次消费 clicks=10 响应，第 2 次消费 clicks=25 响应
        // （注意：本框架 Http::fake($array) 为追加语义，重设 fake 会叠加 stub，须用 sequence）
        Http::fake([
            'https://searchconsole.googleapis.com/*' => Http::sequence()
                ->push(['rows' => [
                    $this->apiRow(now()->subDays(3)->toDateString(), 'https://www.aples.xyz/blog/a', 'valve supplier', 'us', 10, 200, 5.5),
                ]])
                ->push(['rows' => [
                    $this->apiRow(now()->subDays(3)->toDateString(), 'https://www.aples.xyz/blog/a', 'valve supplier', 'us', 25, 220, 5.2),
                ]]),
        ]);
        app(GscFetcher::class)->fetch();

        app(GscFetcher::class)->fetch();

        $this->assertSame(1, GscDaily::count());
        $this->assertSame(25, GscDaily::first()->clicks);
        $this->assertSame(220, GscDaily::first()->impressions);
    }

    public function test_fetch_throws_when_gsc_api_fails(): void
    {
        $this->makeCredential();

        Http::fake([
            'https://searchconsole.googleapis.com/*' => Http::response(
                ['error' => ['message' => 'quota exceeded']], 429
            ),
        ]);

        $this->expectException(RuntimeException::class);

        app(GscFetcher::class)->fetch();
    }

    /* ----------------------------------------------------------
     | 看板聚合（三榜单 + 概览 + 分语言）
     * --------------------------------------------------------- */

    public function test_dashboard_rankings_filter_conditions(): void
    {
        // -- 临门一脚命中 A：两天聚合 avg=7.0（4~15 内）、展现 120、点击 4
        $this->addDaily(['page' => 'https://www.aples.xyz/blog/gate', 'query' => 'gate valve supplier',
            'date' => now()->subDays(3)->toDateString(), 'impressions' => 60, 'clicks' => 2, 'position' => 6.0]);
        $this->addDaily(['page' => 'https://www.aples.xyz/blog/gate', 'query' => 'gate valve supplier',
            'date' => now()->subDays(4)->toDateString(), 'impressions' => 60, 'clicks' => 2, 'position' => 8.0]);

        // -- 临门一脚命中 B：展现 150、点击 9、avg=10（点击更多，应排在 A 之后）
        $this->addDaily(['page' => 'https://www.aples.xyz/blog/ball', 'query' => 'ball valve supplier',
            'impressions' => 150, 'clicks' => 9, 'position' => 10.0]);

        // -- 排除：展现 80 < 100
        $this->addDaily(['page' => 'https://www.aples.xyz/blog/rare', 'query' => 'rare supplier',
            'impressions' => 80, 'clicks' => 1, 'position' => 8.0]);

        // -- 排除：平均排名 25 超出区间
        $this->addDaily(['page' => 'https://www.aples.xyz/blog/deep', 'query' => 'deep rank term',
            'impressions' => 200, 'clicks' => 5, 'position' => 25.0]);

        // -- 机会词命中：展现 60、点击 0
        $this->addDaily(['page' => 'https://www.aples.xyz/blog/tile', 'query' => 'ceramic tile price',
            'impressions' => 60, 'clicks' => 0, 'position' => 9.0]);

        // -- 机会词排除：展现 40 < 50
        $this->addDaily(['page' => 'https://www.aples.xyz/blog/hint', 'query' => 'small hint',
            'impressions' => 40, 'clicks' => 0, 'position' => 9.0]);

        // -- 衰退命中：前 7 天 100 → 近 7 天 40（降 60%）
        $this->addDaily(['page' => 'https://www.aples.xyz/blog/declining', 'query' => 'declining query',
            'date' => now()->subDays(13)->toDateString(), 'clicks' => 50, 'impressions' => 500, 'position' => 6.0]);
        $this->addDaily(['page' => 'https://www.aples.xyz/blog/declining', 'query' => 'declining query',
            'date' => now()->subDays(9)->toDateString(), 'clicks' => 50, 'impressions' => 500, 'position' => 6.0]);
        $this->addDaily(['page' => 'https://www.aples.xyz/blog/declining', 'query' => 'declining query',
            'date' => now()->subDays(5)->toDateString(), 'clicks' => 40, 'impressions' => 300, 'position' => 9.0]);

        // -- 衰退排除：降幅仅 10%
        $this->addDaily(['page' => 'https://www.aples.xyz/blog/stable', 'query' => 'stable query',
            'date' => now()->subDays(12)->toDateString(), 'clicks' => 100, 'impressions' => 900, 'position' => 5.0]);
        $this->addDaily(['page' => 'https://www.aples.xyz/blog/stable', 'query' => 'stable query',
            'date' => now()->subDays(3)->toDateString(), 'clicks' => 90, 'impressions' => 800, 'position' => 5.5]);

        // -- 衰退排除：降幅虽 100% 但前 7 天只有 5 次点击（样本门槛 10）
        $this->addDaily(['page' => 'https://www.aples.xyz/blog/tiny', 'query' => 'tiny query',
            'date' => now()->subDays(10)->toDateString(), 'clicks' => 5, 'impressions' => 60, 'position' => 7.0]);

        $report = app(GscReport::class);

        // 临门一脚：4 条命中（衰退/稳定两页的数据同时满足其筛选条件），按点击升序（4 < 9 < 140 < 190）
        $nearMiss = $report->nearMiss();
        $this->assertSame(
            ['gate valve supplier', 'ball valve supplier', 'declining query', 'stable query'],
            $nearMiss->pluck('query')->all()
        );
        $this->assertSame(4, $nearMiss->first()->clicks);
        $this->assertEqualsWithDelta(7.0, (float) $nearMiss->first()->avg_position, 0.01);

        // 衰退预警：仅 declining 命中，降幅 60%
        $declining = $report->declining();
        $this->assertSame(['https://www.aples.xyz/blog/declining'], $declining->pluck('page')->all());
        $this->assertEqualsWithDelta(60.0, $declining->first()->drop_percent, 0.01);

        // 机会词：仅展现 60 且零点击的命中；有点击与展现不足的被排除
        $opportunities = $report->opportunities();
        $this->assertSame(['ceramic tile price'], $opportunities->pluck('query')->all());
    }

    public function test_stats_and_language_breakdown(): void
    {
        $this->addDaily(['page' => 'https://www.aples.xyz/blog/a', 'query' => 'a', 'clicks' => 50, 'impressions' => 500, 'position' => 4.0]);
        $this->addDaily(['page' => 'https://www.aples.xyz/es/blog/a', 'query' => 'b', 'clicks' => 30, 'impressions' => 300, 'position' => 6.0]);
        $this->addDaily(['page' => 'https://www.aples.xyz/pt/produto/a', 'query' => 'c', 'clicks' => 20, 'impressions' => 200, 'position' => 8.0]);

        $report = app(GscReport::class);

        $stats = $report->stats();

        $this->assertSame(100, $stats['clicks']);
        $this->assertSame(1000, $stats['impressions']);
        $this->assertEqualsWithDelta(6.0, $stats['position'], 0.01);

        // 分语言：/es → es，/pt → pt，无前缀 → 默认语言（en）
        $byLanguage = collect($report->byLanguage())->pluck('clicks', 'locale');

        $this->assertSame(50, $byLanguage['']);
        $this->assertSame(30, $byLanguage['es']);
        $this->assertSame(20, $byLanguage['pt']);
    }

    /* ----------------------------------------------------------
     | OAuth 回调
     * --------------------------------------------------------- */

    public function test_oauth_callback_stores_credentials(): void
    {
        Setting::put('plugin.GscPulse', [
            'client_id' => 'cid-1',
            'client_secret' => 'csecret-1',
            'site_url' => 'https://www.aples.xyz/',
        ]);

        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'at-x', 'refresh_token' => 'rt-x', 'expires_in' => 3600,
                // 回调会校验实际授予的 scope：没有 Search Console 只读权限就不落库
                'scope' => 'https://www.googleapis.com/auth/webmasters.readonly',
            ]),
            // 回调还会确认被授权账号确实能访问这个站点属性，否则"连上了却没数据"
            'https://searchconsole.googleapis.com/webmasters/v3/sites' => Http::response([
                'siteEntry' => [
                    ['siteUrl' => 'https://www.aples.xyz/', 'permissionLevel' => 'FULL'],
                ],
            ]),
        ]);

        session()->put('gsc-pulse.state', 'st-1');

        $this->get('/admin/gsc-pulse/callback?code=good&state=st-1')
            ->assertRedirect(GscConnectPage::getUrl());

        $credential = GscCredential::first();

        $this->assertNotNull($credential);
        $this->assertSame('https://www.aples.xyz/', $credential->site_url);
        $this->assertSame('at-x', $credential->access_token);
        $this->assertSame('rt-x', $credential->refresh_token);
        $this->assertNotNull($credential->token_expires_at);

        // code 换 token 携带了插件设置里的 client_id
        // （回调现在还会多发一次站点属性列表请求，断言要能安全处理没有 body 的请求）
        Http::assertSent(fn ($req) => ($req['code'] ?? null) === 'good' && ($req['client_id'] ?? null) === 'cid-1');
    }

    public function test_oauth_callback_rejects_bad_state_and_denied_error(): void
    {
        $this->makeCredential(); // 预置一行，断言计数不被污染

        // state 不匹配 → 拒绝且不请求 Google
        Http::fake(['https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'x'])]);
        session()->put('gsc-pulse.state', 'st-2');

        $this->get('/admin/gsc-pulse/callback?code=evil&state=wrong')
            ->assertRedirect()
            ->assertSessionHas('gsc_error');

        $this->assertSame(1, GscCredential::count());
        Http::assertNothingSent();

        // 用户在 Google 侧拒绝授权 → 友好回跳，不落库
        session()->put('gsc-pulse.state', 'st-3');

        $this->get('/admin/gsc-pulse/callback?error=access_denied&state=st-3')
            ->assertRedirect()
            ->assertSessionHas('gsc_error');

        $this->assertSame(1, GscCredential::count());
    }

    public function test_oauth_callback_reports_token_exchange_failure(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(
                ['error_description' => 'Invalid authorization code'], 400
            ),
        ]);

        session()->put('gsc-pulse.state', 'st-4');

        $this->get('/admin/gsc-pulse/callback?code=bad&state=st-4')
            ->assertRedirect()
            ->assertSessionHas('gsc_error');

        $this->assertSame(0, GscCredential::count());
    }

    /* ----------------------------------------------------------
     | 连接页（Livewire 保存 / 断开）
     * --------------------------------------------------------- */

    public function test_connect_page_saves_settings_and_disconnects(): void
    {
        // 必填校验：两个都为空不落库
        Livewire::test(GscConnectPage::class)
            ->set('clientId', '')
            ->set('clientSecret', '')
            ->call('save');

        $this->assertTrue(Setting::query()->where('key', 'plugin.GscPulse')->doesntExist());

        // 正常保存：trim + site_url 补尾斜杠
        Livewire::test(GscConnectPage::class)
            ->set('clientId', ' cid-9 ')
            ->set('clientSecret', ' csecret-9 ')
            ->set('siteUrl', 'https://www.aples.xyz')
            ->call('save');

        $values = Setting::get('plugin.GscPulse');

        $this->assertSame('cid-9', $values['client_id']);
        // client_secret 加密存储：库里不再是明文，取用时解密还原
        $this->assertNotSame('csecret-9', $values['client_secret']);
        $this->assertSame('csecret-9', \Modules\GscPulse\Support\OAuthClient::clientSecret());
        $this->assertSame('https://www.aples.xyz/', $values['site_url']);

        // 断开：删除凭据行（OAuth 应用设置保留）
        $this->makeCredential();

        Livewire::test(GscConnectPage::class)->call('disconnect');

        $this->assertSame(0, GscCredential::count());
        $this->assertSame('cid-9', Setting::get('plugin.GscPulse')['client_id']);
    }

    /* ----------------------------------------------------------
     | 页面渲染
     * --------------------------------------------------------- */

    public function test_dashboard_and_connect_pages_render_with_empty_states(): void
    {
        // 看板：未连接 → 提示先连接
        $this->get('/admin/gsc-pulse')
            ->assertOk()
            ->assertSee('先连接 Google Search Console');

        // 连接页渲染 + 出现「连接 Google」入口占位（未保存凭据时为提示文案）
        $this->get('/admin/gsc-pulse-connect')
            ->assertOk()
            ->assertSee('连接状态')
            ->assertSee('保存凭据');

        // 已连接但无数据 → 提示拉取
        $this->makeCredential();

        $this->get('/admin/gsc-pulse')
            ->assertOk()
            ->assertSee('还没有数据');
    }

    public function test_dashboard_renders_rankings_with_data(): void
    {
        $this->makeCredential();

        $this->addDaily([
            'page' => 'https://www.aples.xyz/blog/gate',
            'query' => 'gate valve supplier',
            'impressions' => 120,
            'clicks' => 4,
            'position' => 7.0,
        ]);

        $this->get('/admin/gsc-pulse')
            ->assertOk()
            ->assertSee('临门一脚')
            ->assertSee('衰退预警')
            ->assertSee('机会词')
            ->assertSee('gate valve supplier', false)
            ->assertSee('4', false);
    }
}
