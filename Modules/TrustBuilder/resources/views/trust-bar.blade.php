{{-- 全站信任条（由 Provider 注入 footer_before 插槽，布局页脚上方渲染） --}}
{{-- 渲染时自检（Provider boot 阶段可能早于数据库迁移/测试环境，可见性判断必须在渲染时进行）：
    1. 案例列表/详情页不重复渲染（当前路由是 cases.* 即跳过）；
    2. 无证书且无已发布案例时整条不输出。 --}}
@php
    // 信任条不应出现在案例页自身（路由名带语言前缀，两种模式都要排除）
    $trustBarOnCasesPage = request()->routeIs('cases.*', '*.cases.*');

    // 证书名徽章（最多 6 个，按 sort 升序）+ 已发布案例数
    $trustBarCertificates = $trustBarOnCasesPage ? collect() : \Modules\TrustBuilder\Models\TrustCertification::query()->sorted()->limit(6)->get();
    $trustBarCasesCount = $trustBarOnCasesPage ? 0 : \Modules\TrustBuilder\Models\TrustCase::query()->published()->count();
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
