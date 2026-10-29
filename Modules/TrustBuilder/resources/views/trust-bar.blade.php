{{-- 全站信任条（由 Provider 注入 footer_before 插槽，布局页脚上方渲染） --}}
{{-- 渲染时自检（Provider boot 阶段可能早于数据库迁移/测试环境，可见性判断必须在渲染时进行）：
    1. 案例列表/详情页不重复渲染（当前路由是 cases.* 即跳过）；
    2. 无证书且无已发布案例时整条不输出。 --}}
@php
    // 信任条不应出现在案例页自身（路由名带语言前缀，两种模式都要排除）
    $trustBarOnCasesPage = request()->routeIs('cases.*', '*.cases.*');

    // 证书/案例变动频率极低而每页都渲染：5 分钟缓存（后台保存/删除时失效，见 Provider）
    $trustBarData = $trustBarOnCasesPage ? null : cache()->remember(
        'trust-builder:trust-bar',
        now()->addMinutes(5),
        fn () => [
            'certificates' => \Modules\TrustBuilder\Models\TrustCertification::query()->sorted()->limit(6)->get(),
            'casesCount' => \Modules\TrustBuilder\Models\TrustCase::query()->published()->count(),
        ],
    );

    $trustBarCertificates = $trustBarData['certificates'] ?? collect();
    $trustBarCasesCount = $trustBarData['casesCount'] ?? 0;
@endphp

@if(! $trustBarOnCasesPage && ($trustBarCertificates->isNotEmpty() || $trustBarCasesCount > 0))
    <section class="trust-bar" aria-label="{{ __('trustbuilder.front.trust_title') }}">
        <div class="container trust-bar__inner">
            <span class="trust-bar__label">{{ __('trustbuilder.front.trust_title') }}</span>

            @if($trustBarCertificates->isNotEmpty())
                <ul class="trust-bar__badges">
                    @foreach($trustBarCertificates as $certification)
                        <li class="trust-bar__badge">{{ $certification->name }}</li>
                    @endforeach
                </ul>
            @endif

            <a class="trust-bar__link" href="{{ localized_route('cases.index') }}">
                {{ __('trustbuilder.front.cases_count', ['count' => $trustBarCasesCount]) }} →
            </a>
        </div>
    </section>
@endif
