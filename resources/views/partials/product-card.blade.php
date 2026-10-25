@php($first = $product->images->first())
<a class="product-card" href="{{ localized_route('products.show', ['slug' => $product->slug]) }}">
    <div class="product-card__media">
        @if($first)
            <img src="{{ Storage::url($first->path) }}" alt="{{ $first->getTranslation('alt', app()->getLocale()) }}" loading="lazy">
        @else
            <span class="product-card__placeholder">{{ mb_strtoupper(mb_substr($product->name, 0, 1)) }}</span>
        @endif
    </div>
    <div class="product-card__body">
        @if($product->category)
            <span class="product-card__category">{{ $product->category->name }}</span>
        @endif
        <h3 class="product-card__title">{{ $product->name }}</h3>
        <div class="product-card__meta">
            @if($product->price_min !== null)
                <span class="product-card__price">
                    {{ __('site.products.from') }}
                    {{ $product->price_min }}{{ $product->price_max !== null && $product->price_max != $product->price_min ? ' – '.$product->price_max : '' }}
                    {{ $product->currency }}
                </span>
            @endif
            @if($product->moq)
                <span class="product-card__moq">{{ __('site.products.moq') }}: {{ $product->moq }}</span>
            @endif
        </div>
        <button type="button" class="add-basket-btn"
                data-add-to-basket="{{ $product->id }}"
                data-add-label="{{ __('site.basket.add') }}"
                data-remove-label="{{ __('site.basket.remove') }}"
        >{{ __('site.basket.add') }}</button>
    </div>
</a>
