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
            ->with(['category', 'images'])
            ->when($q !== '', function ($query) use ($q) {
                // name 是多语言 JSON 列：直接在整列上 LIKE 会跨语言命中
                // （搜 "de" 命中任何含 "de" 的语言名），且结果渲染成当前语言后看起来不匹配。
                // 改为只在当前语言的键内做前缀无关的包含匹配。
                //
                // core_keywords / hot_trending_keywords 是单语（英文）关键词数组，
                // 不做语言切分：整列 CAST 后做包含匹配即可。
                $like = '%'.mb_strtolower($q).'%';

                $query->where(function ($query) use ($like) {
                    $query->whereRaw(
                        'json_extract(name, ?) like ?',
                        ['$."'.app()->getLocale().'"', $like]
                    )
                        ->orWhereRaw('cast(core_keywords as char) like ?', [$like])
                        ->orWhereRaw('cast(hot_trending_keywords as char) like ?', [$like]);
                });
            })
            ->orderByDesc('id')
            ->paginate(12)
            ->withQueryString();

        return view('products.index', [
            'products' => $products,
            'q' => $q,
            'hotChips' => Product::hotKeywordChips(2),
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
            ->with(['category', 'images'])
            ->where('category_id', $product->category_id)
            ->whereKeyNot($product->getKey())
            ->take(4)
            ->get();

        $routeParams = ['slug' => $product->slug];

        return view('products.show', [
            'product' => $product,
            'related' => $related,
            'hotChips' => Product::hotKeywordChips(5, $product->category_id),
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
            'brand' => ['@type' => 'Brand', 'name' => site_name()],
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
