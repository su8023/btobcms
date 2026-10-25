<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Support\Seo;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $products = Product::published()
            ->with('category')
            ->when($q !== '', function ($query) use ($q) {
                $query->whereRaw('lower(name) like ?', ['%'.mb_strtolower($q).'%']);
            })
            ->orderByDesc('id')
            ->paginate(12)
            ->withQueryString();

        return view('products.index', [
            'products' => $products,
            'q' => $q,
            'seo' => Seo::page('products.index'),
        ]);
    }

    public function show(string $slug)
    {
        $product = Product::published()
            ->with(['category', 'images'])
            ->where('slug', $slug)
            ->firstOrFail();

        $product->increment('views');

        $related = Product::published()
            ->where('category_id', $product->category_id)
            ->whereKeyNot($product->getKey())
            ->take(4)
            ->get();

        $routeParams = ['slug' => $product->slug];

        return view('products.show', [
            'product' => $product,
            'related' => $related,
            'seo' => Seo::for($product, 'products.show', $routeParams),
            'jsonLd' => $this->productJsonLd($product),
        ]);
    }

    private function productJsonLd(Product $product): array
    {
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $product->name,
            'description' => (string) ($product->summary ?? $product->name),
            'sku' => (string) ($product->sku ?? $product->slug),
            'brand' => ['@type' => 'Brand', 'name' => config('site.name')],
        ];

        if ($product->price_min !== null) {
            $data['offers'] = [
                '@type' => 'AggregateOffer',
                'priceCurrency' => $product->currency,
                'lowPrice' => (float) $product->price_min,
                'highPrice' => $product->price_max !== null ? (float) $product->price_max : (float) $product->price_min,
                'availability' => 'https://schema.org/InStock',
                'url' => localized_route('products.show', ['slug' => $product->slug]),
                ...(filled($product->moq) ? ['eligibleQuantity' => [
                    '@type' => 'QuantitativeValue',
                    'name' => 'MOQ',
                    'value' => $product->moq,
                ]] : []),
            ];
        }

        return $data;
    }
}
