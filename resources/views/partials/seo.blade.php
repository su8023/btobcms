@php
    $seo = $seo ?? [];
    $title = $seo['title'] ?? config('site.name');
    $description = $seo['description'] ?? '';
    $canonical = $seo['canonical'] ?? url()->current();
    $alternates = $seo['alternates'] ?? [];
    $og = $seo['og'] ?? [];
@endphp
<title>{{ $title }}</title>
<meta name="description" content="{{ $description }}">
@if(!empty($noindex))
    <meta name="robots" content="noindex,nofollow">
@endif
<link rel="canonical" href="{{ $canonical }}">
@if(!empty($alternates))
    @foreach($alternates as $altLocale => $href)
        <link rel="alternate" hreflang="{{ $altLocale }}" href="{{ $href }}">
    @endforeach
    <link rel="alternate" hreflang="x-default" href="{{ $alternates[default_locale()] ?? '' }}">
@endif
<meta property="og:title" content="{{ $og['title'] ?? $title }}">
<meta property="og:description" content="{{ $og['description'] ?? $description }}">
<meta property="og:type" content="{{ $og['type'] ?? 'website' }}">
<meta property="og:url" content="{{ $og['url'] ?? $canonical }}">
<meta property="og:image" content="{{ $og['image'] ?? asset('images/og-default.png') }}">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:site_name" content="{{ config('site.name') }}">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:image" content="{{ $og['image'] ?? asset('images/og-default.png') }}">
@if(!empty($jsonLd))
    {{-- 归一化为图的列表：单个图（含 @type 的关联数组）也要包一层，防止 Arr::wrap 按值拆散 --}}
    @php($graphs = isset($jsonLd['@type']) ? [$jsonLd] : (array_values(array_filter(is_array($jsonLd) ? $jsonLd : []))))
    @foreach($graphs as $graph)
        @if(is_array($graph))
            <script type="application/ld+json">{!! json_encode($graph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
        @endif
    @endforeach
@else
    {{-- 兜底：未显式提供结构化数据的页面输出基础 WebPage 图，保证全站 JSON-LD 覆盖 --}}
    <script type="application/ld+json">{!! json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'WebPage',
        'name' => $title,
        'description' => $description,
        'url' => $canonical,
        'inLanguage' => app()->getLocale(),
        'isPartOf' => ['@id' => localized_route('home').'#website'],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
@endif
