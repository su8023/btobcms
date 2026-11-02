{{-- 全站信任条（由 Provider 注入 footer_before 插槽，布局页脚上方渲染） --}}
{{-- 渲染时自检（Provider boot 阶段可能早于数据库迁移/测试环境，可见性判断必须在渲染时进行）：
    1. 案例列表/详情页不重复渲染（当前路由是 cases.* 即跳过）；
    2. 无证书且无已发布案例时整条不输出。 --}}
@php
    // 信任条不应出现在案例页自身（路由名带语言前缀，两种模式都要排除）
    $trustBarOnCasesPage = request()->routeIs('cases.*', '*.cases.*');

    // 表可能不存在：全新安装时若 TrustBuilder 处于停用状态再执行 migrate，
    // 模块迁移没跑 → trust_cases / trust_certifications 两张表都没建。
    // 此时查询直接抛 QueryException，全站每一页（含 404 页）都 500。
    $trustBarTablesReady = \Illuminate\Support\Facades\Schema::hasTable('trust_cases')
        && \Illuminate\Support\Facades\Schema::hasTable('trust_certifications');

    // 证书/案例变动频率极低而每页都渲染：5 分钟缓存。
    // 注意：database cache 白名单反序列化（禁存 Eloquent 对象），
    // 只能缓存标量数组——多语言名称原样入缓存，渲染时按当前语言解析。
    $trustBarData = ($trustBarOnCasesPage || ! $trustBarTablesReady) ? null : cache()->remember(
        'trust-builder:trust-bar',
        now()->addMinutes(5),
        fn () => [
            'badges' => \Modules\TrustBuilder\Models\TrustCertification::query()
                ->sorted()->limit(6)
                ->get(['id', 'name'])
                ->map(fn ($c) => ['name' => $c->getTranslations('name')])
                ->all(),
            'casesCount' => \Modules\TrustBuilder\Models\TrustCase::query()->published()->count(),
        ],
    );

    $trustBarLocale = app()->getLocale();
    $trustBarDefault = default_locale();
@endphp

@if(! $trustBarOnCasesPage && ($trustBarData !== null && ($trustBarData['badges'] !== [] || $trustBarData['casesCount'] > 0)))
    <section class="trust-bar" aria-label="{{ __('trustbuilder.front.trust_title') }}">
        <div class="container trust-bar__inner">
            <span class="trust-bar__label">{{ __('trustbuilder.front.trust_title') }}</span>

            @if($trustBarData['badges'] !== [])
                <ul class="trust-bar__badges">
                    @foreach($trustBarData['badges'] as $badge)
                        <li class="trust-bar__badge">{{ $badge['name'][$trustBarLocale] ?? $badge['name'][$trustBarDefault] ?? '' }}</li>
                    @endforeach
                </ul>
            @endif

            <a class="trust-bar__link" href="{{ localized_route('cases.index') }}">
                {{ __('trustbuilder.front.cases_count', ['count' => $trustBarData['casesCount']]) }} →
            </a>
        </div>
    </section>
@endif
