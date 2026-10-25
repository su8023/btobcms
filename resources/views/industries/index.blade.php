@extends('layouts.app')

@section('content')
<section class="page-head">
    <div class="container">
        <h1>{!! __('industries.index_title') !!}</h1>
        <p>{!! __('industries.index_sub') !!}</p>
    </div>
</section>

<section class="section">
    <div class="container">
        {{-- 行业总览导语：索引页内容深度 + 长尾关键词承载 --}}
        <div class="section-head">
            <h2>{{ __('industries.browse_title') }}</h2>
            <p class="section-sub">{!! __('industries.index_body') !!}</p>
        </div>
        <div class="grid grid-3">
            @foreach($industries as $industry)
                <a class="category-card" href="{{ localized_route('industries.show', ['slug' => $industry['slug']]) }}">
                    <div class="sw-cat-card__head">
                        <h3>{{ $industry['name'] }}</h3>
                        <span class="sw-cat-card__arrow" aria-hidden="true">→</span>
                    </div>
                </a>
            @endforeach
        </div>

        {{-- 尾段：处理"找不到对应品类"的长尾意图，直接引导询盘 --}}
        <div class="industries-footer-note">
            <p>{!! __('industries.index_footer') !!}</p>
        </div>
    </div>
</section>

<section class="cta-band">
    <div class="container">
        <h2>{{ __('site.cta.band_title') }}</h2>
        <p>{{ config('site.contact.email') }} · {{ config('site.contact.phone') }}</p>
        <a href="{{ localized_route('contact') }}" class="btn btn-invert btn-lg">{{ __('site.cta.inquiry') }}</a>
    </div>
</section>
@endsection
