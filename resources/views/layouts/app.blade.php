@php($siteName = site_setting('site.name', config('site.name')))
@php($siteEmail = site_setting('site.contact.email', config('site.contact.email')))
@php($sitePhone = site_setting('site.contact.phone', config('site.contact.phone')))
@php($siteAddress = site_setting('site.contact.address', config('site.contact.address')))
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" dir="{{ is_rtl_locale() ? 'rtl' : 'ltr' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
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
<header class="site-header">
    <div class="topbar">
        <div class="container topbar-inner">
            <div class="topbar-contact">
                <span>✉ {{ $siteEmail }}</span>
                <span class="topbar-sep">·</span>
                <span>☎ {{ $sitePhone }}</span>
            </div>
            @include('partials.language-switcher')
        </div>
    </div>
    <div class="navbar">
        <div class="container navbar-inner">
            <a href="{{ localized_route('home') }}" class="brand">
                <span class="brand-mark">B2B</span>
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
            <a href="{{ localized_route('basket') }}" class="basket-link" title="{{ __('site.basket.title') }}">
                <span aria-hidden="true">🧺</span>
                <span id="basket-count" class="basket-count" hidden>0</span>
            </a>
            <a href="{{ localized_route('contact') }}" class="btn btn-primary nav-cta">{{ __('site.cta.quote') }}</a>
            <button class="nav-toggle" aria-label="Menu" onclick="document.getElementById('site-nav').classList.toggle('open')">☰</button>
        </div>
    </div>
</header>

<main class="site-main">
    @yield('content')
</main>

<footer class="site-footer">
    {{-- 插件浮动插槽（客服挂件等） --}}
    @foreach(\App\Support\Slots::get('floating') as $floatingView)
        @include($floatingView)
    @endforeach
    <div class="container footer-grid">
        <div>
            <div class="brand">
                <span class="brand-mark">B2B</span>
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
                <li>✉ {{ $siteEmail }}</li>
                <li>☎ {{ $sitePhone }}</li>
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
