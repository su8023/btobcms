@extends('layouts.app')

@section('content')
<section class="section">
    <div class="container">
        <nav class="breadcrumb">
            <a href="{{ localized_route('home') }}">{{ __('site.nav.home') }}</a> /
            <span>{{ $category->name }}</span>
        </nav>

        <div class="section-head">
            <h1>{{ $category->name }}</h1>
            <span class="section-count">{{ $products->total() }} {{ __('site.category.products_in') }}</span>
        </div>

        @if($subcategories->isNotEmpty())
            <div class="chip-row">
                @foreach($subcategories as $sub)
                    <a class="chip chip-link" href="{{ localized_route('categories.show', ['slug' => $sub->slug]) }}">
                        {{ $sub->name }} <small>({{ $sub->products_count }} {{ trans_choice('site.category.products_in', $sub->products_count) }})</small>
                    </a>
                @endforeach
            </div>
        @endif

        <div class="category-layout">
            @if($facets->isNotEmpty())
                <aside class="facet-panel">
                    <h3>{{ __('site.category.filter') }}</h3>
                    <form method="get">
                        @foreach($facets as $facet)
                            <div class="form-field">
                                <label>{{ $facet->name }}</label>
                                <select name="f[{{ $facet->code }}]">
                                    <option value="">—</option>
                                    @foreach((array) $facet->options as $option)
                                        <option value="{{ $option }}"
                                            @if(($activeFilters[$facet->code] ?? '') === (string) $option) selected @endif
                                        >{{ $option }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endforeach
                        <button type="submit" class="btn btn-primary btn-sm">{{ __('site.category.filter') }}</button>
                        <a class="btn btn-ghost btn-sm" href="{{ localized_route('categories.show', ['slug' => $category->slug]) }}">{{ __('site.category.clear') }}</a>
                    </form>
                </aside>
            @endif

            <div class="category-main">
                @if($products->isEmpty())
                    <p class="empty-state">{{ __('site.products.no_results') }}</p>
                @else
                    <div class="grid grid-3">
                        @foreach($products as $product)
                            @include('partials.product-card', ['product' => $product])
                        @endforeach
                    </div>
                    {{ $products->links('partials.pagination') }}
                @endif
            </div>
        </div>

        @if($category->description)
            <div class="category-description">{!! $category->description !!}</div>
        @endif
    </div>
</section>
@endsection
