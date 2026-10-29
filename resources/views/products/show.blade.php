@extends('layouts.app')

@php
    $firstImage = $product->images->first();
    $attrDefs = $product->category?->attrSet?->attributes->keyBy('code');
@endphp

@section('content')
<section class="section">
    <div class="container">
        <nav class="breadcrumb">
            <a href="{{ localized_route('home') }}">{{ __('site.nav.home') }}</a> /
            @if($product->category)
                <a href="{{ localized_route('categories.show', ['slug' => $product->category->slug]) }}">{{ $product->category->name }}</a> /
            @endif
            <span>{{ $product->name }}</span>
        </nav>

        <div class="product-layout">
            <div class="product-gallery">
                @if($firstImage)
                    {{-- ImageForge 提供 picture/webp；模块停用时退化为原生 img --}}
                    {!! function_exists('imageforge_picture')
                        ? \imageforge_picture($firstImage->path, $firstImage->getTranslation('alt', app()->getLocale()))
                        : '<img src="'.e(Storage::url($firstImage->path)).'" alt="'.e($firstImage->getTranslation('alt', app()->getLocale())).'" loading="lazy">' !!}
                @else
                    <div class="product-gallery__placeholder">{{ mb_strtoupper(mb_substr($product->name, 0, 1)) }}</div>
                @endif
            </div>

            <div class="product-info">
                <h1>{{ $product->name }}</h1>

                @if($product->summary)
                    <p class="product-summary">{{ $product->summary }}</p>
                @endif

                <div class="product-chips">
                    @if($product->price_min !== null)
                        <span class="chip chip-price">
                            {{ __('site.products.from') }}
                            {{ $product->price_min }}{{ $product->price_max !== null && $product->price_max != $product->price_min ? ' – '.$product->price_max : '' }}
                            {{ $product->currency }}
                        </span>
                    @endif
                    @if($product->moq)
                        <span class="chip">{{ __('site.products.moq') }}: {{ $product->moq }}</span>
                    @endif
                    @if($product->sku)
                        <span class="chip">SKU: {{ $product->sku }}</span>
                    @endif
                </div>

                @if(!empty($product->attrs))
                    <h2 class="product-subhead">{{ __('site.products.specs') }}</h2>
                    <table class="spec-table">
                        @foreach($product->attrs as $code => $value)
                            <tr>
                                <th>{{ $attrDefs[$code]->name ?? $code }}{{ $attrDefs[$code]->unit ?? null ? ' ('.$attrDefs[$code]->unit.')' : '' }}</th>
                                <td>{{ is_array($value) ? implode(', ', $value) : $value }}</td>
                            </tr>
                        @endforeach
                    </table>
                @endif

                @if($product->description)
                    <div class="product-description">{!! $product->description !!}</div>
                @endif
            </div>

            <aside class="product-inquiry" id="inquiry">
                <h2>{{ __('site.cta.inquiry') }}</h2>
                <p class="product-inquiry__hint">{{ __('site.products.request_quote') }}</p>
                <button type="button" class="btn btn-ghost add-basket-btn-lg"
                        data-add-to-basket="{{ $product->id }}"
                        data-add-label="{{ __('site.basket.add') }}"
                        data-remove-label="{{ __('site.basket.remove') }}"
                >{{ __('site.basket.add') }}</button>
                @include('partials.inquiry-form', ['product' => $product, 'source' => 'product'])
            </aside>
        </div>

        @if($related->isNotEmpty())
            <div class="section-head">
                <h2>{{ __('site.products.related') }}</h2>
            </div>
            <div class="grid grid-4">
                @foreach($related as $relProduct)
                    @include('partials.product-card', ['product' => $relProduct])
                @endforeach
            </div>
        @endif
    </div>
</section>
@endsection
