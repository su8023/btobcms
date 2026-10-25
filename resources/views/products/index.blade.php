@extends('layouts.app')

@section('content')
<section class="page-head">
    <div class="container">
        <h1>@if($q !== ''){{ __('site.products.results_for') }} “{{ $q }}”@else{{ __('site.products.title') }}@endif</h1>
        <p>{{ __('site.products.subtitle') }}</p>
    </div>
</section>

<section class="section">
    <div class="container">
        <form class="search-bar" method="get" action="{{ localized_route('products.index') }}" role="search">
            <input type="search" name="q" value="{{ $q }}" placeholder="{{ __('site.products.search_ph') }}">
            <button type="submit" class="btn btn-primary">🔍</button>
        </form>

        <p class="section-count">{{ trans_choice('site.products.showing', $products->total(), ['count' => $products->total()]) }}</p>

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
</section>
@endsection
