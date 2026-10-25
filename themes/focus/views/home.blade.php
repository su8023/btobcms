@extends('layouts.app')

@section('content')
{{-- ===== 首屏：左文案 + 右询盘表单（转化路径最短） ===== --}}
<section class="fz-hero">
    <div class="container fz-hero-grid">
        <div class="fz-hero-copy">
            <span class="hero-eyebrow">✳ {{ __('site.brand_slogan') }}</span>
            <h1>{!! __('site.home.hero_title_html') !!}</h1>
            <p>{{ __('site.home.hero_sub') }}</p>

            <ul class="fz-bullets">
                @foreach(__('site.home.guarantees') as $guarantee)
                    <li>{{ $guarantee }}</li>
                @endforeach
            </ul>

            <div class="hero-actions">
                <a href="{{ localized_route('products.index') }}" class="btn btn-ghost btn-lg">{{ __('site.nav.products') }}</a>
            </div>

            <div class="hero-hot" style="margin-top: 22px">
                <span class="hero-hot__label">{{ __('site.home.hot') }}</span>
                @foreach(config('site.hot_keywords') as $keyword)
                    <a class="hero-hot__link" href="{{ localized_route('products.index', ['q' => $keyword]) }}">{{ $keyword }}</a>
                @endforeach
            </div>
        </div>

        <div class="fz-form-card">
            <h3>{{ __('site.home.form_card_title') }}</h3>
            @include('partials.inquiry-form', ['source' => 'hero'])
        </div>
    </div>

    <div class="container">
        <div class="hero-stats">
            <div class="hero-stat">
                <div class="hero-stat__num">10<span>+</span></div>
                <div class="hero-stat__label">{{ __('site.stats.years') }}</div>
            </div>
            <div class="hero-stat">
                <div class="hero-stat__num">{{ $stats['products'] }}<span>+</span></div>
                <div class="hero-stat__label">{{ __('site.stats.products') }}</div>
            </div>
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

{{-- ===== 认证徽章条 ===== --}}
<section class="guarantees" aria-label="{{ __('site.home.guarantees_title') }}">
    <div class="container guarantees-row">
        @foreach(__('site.hero.certs') as $cert)
            <span class="guarantee">{{ $cert }}</span>
        @endforeach
    </div>
</section>

{{-- ===== 产品优先（聚焦询盘的买家最想看货） ===== --}}
@if($latestProducts->isNotEmpty())
<section class="section">
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

{{-- ===== 行业区 ===== --}}
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
                    <span class="category-card__count">{{ $category->products_count }} {{ __('site.category.products_in') }}</span>
                </a>
            @endforeach
        </div>
    </div>
</section>
@endif

{{-- ===== 采购流程 ===== --}}
<section class="section" aria-label="{{ __('site.home.process_title') }}">
    <div class="container">
        <div class="section-head">
            <div>
                <span class="section-eyebrow">{{ __('site.home.process_eyebrow') }}</span>
                <h2>{{ __('site.home.process_title') }}</h2>
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

@if($latestPosts->isNotEmpty())
<section class="section section--alt" aria-label="{{ __('site.home.latest_posts') }}">
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
