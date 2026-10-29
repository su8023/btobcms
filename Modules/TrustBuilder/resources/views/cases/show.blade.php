{{-- 案例详情页：面包屑 + 标题 + meta（customer/industry/country）+ 富文本 + 图集 + 相关案例 + CTA --}}
@extends('layouts.app')

@section('content')
<section class="section">
    <div class="container container-narrow">
        <nav class="breadcrumb">
            <a href="{{ localized_route('home') }}">{{ __('site.nav.home') }}</a> /
            <a href="{{ localized_route('cases.index') }}">{{ __('trustbuilder.nav.cases') }}</a> /
            <span>{{ $case->title }}</span>
        </nav>

        <article class="article">
            <h1>{{ $case->title }}</h1>

            {{-- meta 行：customer / industry / country（空值不渲染对应徽章） --}}
            <div class="trust-case-meta">
                <span class="trust-case-meta__chip">{{ __('trustbuilder.front.meta_customer') }}: <strong>{{ $case->customer_name }}</strong></span>
                @if($case->industry)
                    <span class="trust-case-meta__chip">{{ __('trustbuilder.front.meta_industry') }}: <strong>{{ $case->industry }}</strong></span>
                @endif
                @if($case->country)
                    <span class="trust-case-meta__chip">{{ __('trustbuilder.front.meta_country') }}: <strong>{{ $case->country }}</strong></span>
                @endif
            </div>

            {{-- 富文本正文（后台 RichEditor 存 HTML，需转义输出） --}}
            <div class="article-body">{!! $case->body !!}</div>

            {{-- 图集：多图网格，空数组不渲染 --}}
            @if(count($case->imageUrls()) > 0)
                <div class="trust-case-gallery">
                    @foreach($case->imageUrls() as $imageUrl)
                        <img src="{{ $imageUrl }}" alt="{{ $case->customer_name }} — {{ $case->title }}" loading="lazy">
                    @endforeach
                </div>
            @endif
        </article>

        {{-- 相关案例：同行业已发布最多 3 条（控制器保证），无则整块不渲染 --}}
        @if($relatedCases->isNotEmpty())
            <h2>{{ __('trustbuilder.front.related_cases') }}</h2>
            <div class="grid grid-3" style="margin-bottom: 24px">
                @foreach($relatedCases as $related)
                    <a class="trust-case-card" href="{{ localized_route('cases.show', ['slug' => $related->slug]) }}">
                        <span class="trust-case-card__media">
                            @if($related->coverUrl())
                                <img src="{{ $related->coverUrl() }}" alt="{{ $related->customer_name }}" loading="lazy">
                            @endif
                        </span>
                        <span class="trust-case-card__body">
                            <span class="trust-case-card__customer">{{ $related->customer_name }}</span>
                            <span class="trust-case-card__title">{{ $related->title }}</span>
                            <span class="trust-case-card__more">{{ __('trustbuilder.front.view_case') }} →</span>
                        </span>
                    </a>
                @endforeach
            </div>
        @endif

        {{-- 转化 CTA + 返回列表 --}}
        <div style="display: flex; gap: 12px; flex-wrap: wrap">
            <a class="btn btn-primary" href="{{ localized_route('contact') }}">{{ __('site.cta.quote') }}</a>
            <a class="btn btn-ghost" href="{{ localized_route('cases.index') }}">← {{ __('trustbuilder.front.back_to_cases') }}</a>
        </div>
    </div>
</section>
@endsection
