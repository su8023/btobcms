@php($siteName = site_setting('site.name', config('site.name')))
@php($siteEmail = site_setting('site.contact.email', config('site.contact.email')))
@php($sitePhone = site_setting('site.contact.phone', config('site.contact.phone')))
@php($siteAddress = site_setting('site.contact.address', config('site.contact.address')))
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ is_rtl_locale() ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="apple-touch-icon" href="{{ asset('favicon.svg') }}">
    @include('partials.seo', ['seo' => $seo ?? null, 'jsonLd' => $jsonLd ?? null, 'noindex' => $noindex ?? false])
    <link rel="stylesheet" href="{{ asset('css/site.css') }}?v={{ filemtime(public_path('css/site.css')) }}">
    {{-- 激活主题的样式（在核心之后加载，可覆盖任意变量与组件样式） --}}
    @if($themeAssetsUrl = \App\Services\ThemeManager::assetsUrl())
        <link rel="stylesheet" href="{{ $themeAssetsUrl }}/css/theme.css">
    @endif
    {{-- 站点设置里的自定义 CSS（后台 → 站点设置 → 外观） --}}
    @if($customCss = site_setting('site.custom_css'))
        <style>{!! $customCss !!}</style>
    @endif
    <script defer src="{{ asset('js/site.js') }}"></script>
    {{-- GA4 统计：.env 配 GA4_ID 即启用 --}}
    @if(config('site.analytics.ga4'))
        <script async src="https://www.googletagmanager.com/gtag/js?id={{ config('site.analytics.ga4') }}"></script>
        <script>
            window.dataLayer = window.dataLayer || [];
            function gtag() { dataLayer.push(arguments); }
            gtag('js', new Date());
            gtag('config', '{{ config('site.analytics.ga4') }}');
        </script>
    @endif
    {{-- 插件 head 插槽（样式/meta 注入） --}}
    @foreach(\App\Support\Slots::get('head') as $headView)
        @include($headView)
    @endforeach
</head>
<body>
{{-- 管理员专属：APP_URL 与实际访问不一致的提醒与一键同步（HTTPS 切换免 SSH 改 .env）；
    混合内容拦截后台 JS 时，这里的纯 HTML 表单是唯一可用的修复入口 --}}
@includeWhen(user_is_admin(), 'partials.admin-app-url-notice')
<header class="site-header">
    <div class="topbar">
        <div class="container topbar-inner">
            <div class="topbar-contact">
                <span>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></svg>
                    {{ $siteEmail }}
                </span>
                <span>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                    {{ $sitePhone }}
                </span>
            </div>
            @include('partials.language-switcher')
        </div>
    </div>
    <div class="navbar">
        <div class="container navbar-inner">
            <a href="{{ localized_route('home') }}" class="brand">
                @include('partials.brand-logo')
                <span class="brand-name">{{ $siteName }}</span>
            </a>
            <nav class="nav" id="site-nav">
                <a href="{{ localized_route('home') }}">{{ __('site.nav.home') }}</a>
                <a href="{{ localized_route('industries.index') }}" @if(request()->routeIs('*.industries.*')) aria-current="page" @endif>{{ __('site.nav.industries') }}</a>
                <a href="{{ localized_route('services.index') }}" @if(request()->routeIs('*.services.*')) aria-current="page" @endif>{{ __('site.nav.services') }}</a>
                <a href="{{ localized_route('blog.index') }}">{{ __('site.nav.blog') }}</a>
                <a href="{{ localized_route('about') }}">{{ __('site.nav.about') }}</a>
                <a href="{{ localized_route('contact') }}">{{ __('site.nav.contact') }}</a>
            </nav>
            <a href="{{ localized_route('basket') }}" class="basket-link" title="{{ __('site.basket.title') }}" aria-label="{{ __('site.basket.title') }}">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 9h16l-1.31 11.11a2 2 0 0 1-1.99 1.79H7.3a2 2 0 0 1-1.99-1.79L4 9z"/><path d="M8 9V6a4 4 0 0 1 8 0v3"/></svg>
                <span id="basket-count" class="basket-count" hidden>0</span>
            </a>
            <a href="{{ localized_route('contact') }}" class="btn btn-primary nav-cta">{{ __('site.cta.quote') }}</a>
            <button class="nav-toggle" aria-label="Menu" onclick="document.getElementById('site-nav').classList.toggle('open')">☰</button>
        </div>
    </div>
</header>

<main class="site-main">
    {{-- 插件插槽：正文顶部（公告横幅/活动条等） --}}
    @foreach(\App\Support\Slots::get('body_top') as $bodyTopView)
        @include($bodyTopView)
    @endforeach
    @yield('content')
</main>

<footer class="site-footer">
    {{-- 插件插槽：页脚上方（信任徽章行/订阅表单等） --}}
    @foreach(\App\Support\Slots::get('footer_before') as $footerView)
        @include($footerView)
    @endforeach
    {{-- 插件浮动插槽（客服挂件等） --}}
    @foreach(\App\Support\Slots::get('floating') as $floatingView)
        @include($floatingView)
    @endforeach
    <div class="container footer-grid">
        <div>
            <div class="brand">
                @include('partials.brand-logo')
                <span class="brand-name">{{ $siteName }}</span>
            </div>
            <p class="footer-about">{{ __('site.brand_slogan') }} — {{ $siteAddress }}</p>
        </div>
        <div>
            <h4>{{ __('site.footer.links') }}</h4>
            <ul class="footer-links">
                <li><a href="{{ localized_route('industries.index') }}">{{ __('site.nav.industries') }}</a></li>
                <li><a href="{{ localized_route('services.index') }}">{{ __('site.nav.services') }}</a></li>
                <li><a href="{{ localized_route('blog.index') }}">{{ __('site.nav.blog') }}</a></li>
                <li><a href="{{ localized_route('about') }}">{{ __('site.nav.about') }}</a></li>
                <li><a href="{{ localized_route('contact') }}">{{ __('site.nav.contact') }}</a></li>
            </ul>
        </div>
        <div>
            <h4>{{ __('site.footer.contact') }}</h4>
            <ul class="footer-links">
                <li>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></svg>
                    {{ $siteEmail }}
                </li>
                <li>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                    {{ $sitePhone }}
                </li>
            </ul>
        </div>
    </div>
    <div class="footer-bottom">
        <div class="container footer-bottom-inner">
            <span>© {{ date('Y') }} {{ $siteName }}. {{ __('site.footer.copyright') }}</span>
            <span class="footer-legal">
                <a href="{{ localized_route('privacy') }}">{{ __('site.footer_privacy') }}</a>
                <a href="{{ localized_route('terms') }}">{{ __('site.footer_terms') }}</a>
            </span>
        </div>
    </div>
</footer>
    {{-- 移动端底部常驻 CTA 条（转化优先，仅 ≤768px 显示） --}}
    <div class="mobile-cta" aria-hidden="false">
        <a href="{{ localized_route('industries.index') }}" class="mobile-cta__btn">{{ __('site.nav.industries') }}</a>
        <a href="{{ localized_route('contact') }}" class="mobile-cta__btn mobile-cta__btn--primary">{{ __('site.cta.quote') }}</a>
    </div>
</body>
</html>
