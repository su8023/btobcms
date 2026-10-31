@extends('layouts.app')

@section('content')<section class="hero">
    <div class="container">
        <div class="hero-grid">
            <div class="hero-copy">
                <span class="hero-eyebrow">{{ __('site.brand_slogan') }}</span>
                <h1>{!! __('site.home.hero_title_html') !!}</h1>
                <p>{{ __('site.home.hero_sub') }}</p>

                {{-- 认证与合规前置（工业买家首要信任信号；占位内容，替换为真实认证编号） --}}
                <div class="hero-certs">
                    @foreach(__('site.hero.certs') as $cert)
                        <span class="hero-cert">{{ $cert }}</span>
                    @endforeach
                </div>
            </div>

            {{-- 首屏询盘表单：纯线索站的第一转化点（无需跳转即可提交 RFQ） --}}
            <aside class="hero-card" id="rfq">
                <h2>{{ __('site.home.form_card_title') }}</h2>
                @include('partials.inquiry-form', ['source' => 'home'])
            </aside>
        </div>

        <div class="hero-stats">
            <div class="hero-stat">
                <div class="hero-stat__num">10<span>+</span></div>
                <div class="hero-stat__label">{{ __('site.stats.years') }}</div>
            </div>
            @if($stats['products'] > 0)
                <div class="hero-stat">
                    <div class="hero-stat__num">{{ $stats['products'] }}<span>+</span></div>
                    <div class="hero-stat__label">{{ __('site.stats.products') }}</div>
                </div>
            @else
                <div class="hero-stat">
                    <div class="hero-stat__num">{{ $stats['industries'] }}</div>
                    <div class="hero-stat__label">{{ __('site.stats.industries') }}</div>
                </div>
            @endif
            <div class="hero-stat">
                <div class="hero-stat__num">40<span>+</span></div>
                <div class="hero-stat__label">{{ __('site.stats.countries') }}</div>
            </div>
            <div class="hero-stat">
                <div class="hero-stat__num">24<span>h</span></div>
                <div class="hero-stat__label">{{ __('site.stats.response') }}</div>
            </div>
        </div>
    </div>
</section>

{{-- 保障承诺条（高/低承诺 CTA 分层：不愿立即询盘的买家先看到风险保障） --}}
<section class="guarantees" aria-label="{{ __('site.home.guarantees_title') }}">
    <div class="container guarantees-row">
        @foreach(__('site.home.guarantees') as $guarantee)
            <span class="guarantee">{{ $guarantee }}</span>
        @endforeach
    </div>
</section>

{{-- 行业入口：买家按行业找方案，行业页本身就是 SEO 落地页 --}}
<section class="section" aria-label="{{ __('site.home.industries_title') }}">
    <div class="container">
        <div class="section-head">
            <div>
                <span class="section-eyebrow">{{ __('site.home.industries_eyebrow') }}</span>
                <h2>{{ __('site.home.industries_title') }}</h2>
                <p class="section-sub">{{ __('site.home.industries_sub') }}</p>
            </div>
            <a class="section-more" href="{{ localized_route('industries.index') }}">{{ __('site.home.view_all') }} →</a>
        </div>
        <div class="grid grid-4">
            @foreach($industries->take(8) as $industry)
                <a class="ind-card" href="{{ localized_route('industries.show', ['slug' => $industry['slug']]) }}">
                    <span class="ind-card__icon" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $industry['icon'] }}"/></svg>
                    </span>
                    <h3>{{ $industry['name'] }}</h3>
                    <span class="ind-card__arrow" aria-hidden="true">→</span>
                </a>
            @endforeach
        </div>
    </div>
</section>

<section class="section section--alt" aria-label="{{ __('site.home.why_us') }}">
    <div class="container">
        <div class="section-head">
            <div>
                <span class="section-eyebrow">{{ __('site.home.why_eyebrow') }}</span>
                <h2>{{ __('site.home.why_us') }}</h2>
                <p class="section-sub">{{ __('site.home.why_sub') }}</p>
            </div>
        </div>
        <div class="features">
            <div class="feature">
                <div class="feature__icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 21V9l6-4v4l6-4v4l6-4v16H3Z"/><path d="M7 17h2m4-2h2m-6 2h.01M15 17h2"/></svg>
                </div>
                <h3>{{ __('site.features.factory_title') }}</h3>
                <p>{{ __('site.features.factory_text') }}</p>
            </div>
            <div class="feature">
                <div class="feature__icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l1.9 5.7a2 2 0 0 0 1.3 1.3L21 12l-5.8 1.9a2 2 0 0 0-1.3 1.3L12 21l-1.9-5.8a2 2 0 0 0-1.3-1.3L3 12l5.8-2a2 2 0 0 0 1.3-1.3L12 3Z"/></svg>
                </div>
                <h3>{{ __('site.features.oem_title') }}</h3>
                <p>{{ __('site.features.oem_text') }}</p>
            </div>
            <div class="feature">
                <div class="feature__icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l8 3v6c0 4.5-3.2 7.7-8 9-4.8-1.3-8-4.5-8-9V6l8-3Z"/><path d="m9 12 2 2 4-4"/></svg>
                </div>
                <h3>{{ __('site.features.qc_title') }}</h3>
                <p>{{ __('site.features.qc_text') }}</p>
            </div>
            <div class="feature">
                <div class="feature__icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.6 3.9 5.7 3.9 9S14.5 18.4 12 21c-2.5-2.6-3.9-5.7-3.9-9S9.5 5.6 12 3Z"/></svg>
                </div>
                <h3>{{ __('site.features.ship_title') }}</h3>
                <p>{{ __('site.features.ship_text') }}</p>
            </div>
        </div>
    </div>
</section>

{{-- 采购流程（编号步骤：首次进口买家的信任装置） --}}
<section class="section" aria-label="{{ __('site.home.process_title') }}">
    <div class="container">
        <div class="section-head">
            <div>
                <span class="section-eyebrow">{{ __('site.home.process_eyebrow') }}</span>
                <h2>{{ __('site.home.process_title') }}</h2>
                <p class="section-sub">{{ __('site.home.process_sub') }}</p>
            </div>
        </div>
        <ol class="process">
            @foreach(__('site.home.process_steps') as $step)
                <li class="process-step">
                    <span class="process-step__num">{{ $loop->iteration }}</span>
                    <h3>{{ $step['title'] }}</h3>
                    <p>{{ $step['text'] }}</p>
                </li>
            @endforeach
        </ol>
    </div>
</section>

@if($rootCategories->isNotEmpty())
<section class="section section--alt" aria-label="{{ __('site.home.categories') }}">
    <div class="container">
        <div class="section-head">
            <div>
                <span class="section-eyebrow">{{ __('site.home.categories_eyebrow') }}</span>
                <h2>{{ __('site.home.categories') }}</h2>
            </div>
            <a class="section-more" href="{{ localized_route('products.index') }}">{{ __('site.home.view_all') }} →</a>
        </div>
        <div class="grid grid-4">
            @foreach($rootCategories as $category)
                <a class="category-card" href="{{ localized_route('categories.show', ['slug' => $category->slug]) }}">
                    <h3>{{ $category->name }}</h3>
                    <span class="category-card__count">{{ $category->products_count }} {{ trans_choice('site.category.products_in', $category->products_count) }}</span>
                </a>
            @endforeach
        </div>
    </div>
</section>
@endif

@if($latestProducts->isNotEmpty())
<section class="section" aria-label="{{ __('site.home.latest_products') }}">
    <div class="container">
        <div class="section-head">
            <div>
                <span class="section-eyebrow">{{ __('site.home.products_eyebrow') }}</span>
                <h2>{{ __('site.home.latest_products') }}</h2>
            </div>
            <a class="section-more" href="{{ localized_route('products.index') }}">{{ __('site.home.view_all') }} →</a>
        </div>
        <div class="grid grid-4">
            @foreach($latestProducts as $product)
                @include('partials.product-card', ['product' => $product])
            @endforeach
        </div>
    </div>
</section>
@endif

@if($caseStudies->isNotEmpty())
<section class="section section--alt" aria-label="{{ __('site.home.cases_title') }}">
    <div class="container">
        <div class="section-head">
            <div>
                <span class="section-eyebrow">{{ __('site.home.cases_eyebrow') }}</span>
                <h2>{{ __('site.home.cases_title') }}</h2>
            </div>
            <a class="section-more" href="{{ localized_route('blog.index') }}">{{ __('site.home.view_all') }} →</a>
        </div>
        <div class="grid grid-3">
            @foreach($caseStudies as $post)
                <a class="post-card" href="{{ localized_route('blog.show', ['slug' => $post->slug]) }}">
                    <span class="post-card__date">{{ optional($post->published_at)->format('Y-m-d') }}</span>
                    <h3>{{ $post->title }}</h3>
                    <p>{{ \Illuminate\Support\Str::limit(strip_tags((string) $post->excerpt), 120) }}</p>
                    <span class="post-card__more">{{ __('site.blog.read_more') }} →</span>
                </a>
            @endforeach
        </div>
    </div>
</section>
@endif

@if($latestPosts->isNotEmpty())
<section class="section {{ $caseStudies->isNotEmpty() ? '' : 'section--alt' }}" aria-label="{{ __('site.home.latest_posts') }}">
    <div class="container">
        <div class="section-head">
            <div>
                <span class="section-eyebrow">{{ __('site.home.blog_eyebrow') }}</span>
                <h2>{{ __('site.home.latest_posts') }}</h2>
            </div>
            <a class="section-more" href="{{ localized_route('blog.index') }}">{{ __('site.home.view_all') }} →</a>
        </div>
        <div class="grid grid-3">
            @foreach($latestPosts as $post)
                <a class="post-card" href="{{ localized_route('blog.show', ['slug' => $post->slug]) }}">
                    <span class="post-card__date">{{ optional($post->published_at)->format('Y-m-d') }}</span>
                    <h3>{{ $post->title }}</h3>
                    <p>{{ \Illuminate\Support\Str::limit(strip_tags((string) $post->excerpt), 120) }}</p>
                    <span class="post-card__more">{{ __('site.blog.read_more') }} →</span>
                </a>
            @endforeach
        </div>
    </div>
</section>
@endif

<section class="cta-band">
    <div class="container">
        <h2>{{ __('site.cta.band_title') }}</h2>
        <p>{{ config('site.contact.email') }} · {{ config('site.contact.phone') }}</p>
        <a href="{{ localized_route('contact') }}" class="btn btn-invert btn-lg">{{ __('site.cta.inquiry') }}</a>
    </div>
</section>
@endsection
