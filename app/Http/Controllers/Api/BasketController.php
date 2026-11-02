<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BasketController extends Controller
{
    /**
     * 询盘篮产品数据：GET /api/basket/products?ids=1,5,9
     * 返回按当前语言翻译后的产品摘要，供前端渲染询盘篮列表。
     */
    public function index(Request $request): JsonResponse
    {
        // ids 只接受逗号分隔字符串（site.js 的调用格式）。攻击者可用 ?ids[]=1
        // 传数组进来：explode(',', array) 抛 TypeError → 500。非字符串一律按空处理。
        $rawIds = $request->query('ids', '');

        $ids = collect(explode(',', is_string($rawIds) ? $rawIds : ''))
            ->map(fn ($v) => (int) trim($v))
            ->filter()
            ->unique()
            ->take(50)
            ->values();

        $products = Product::published()
            ->with('category')
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $items = $ids
            ->filter(fn (int $id) => $products->has($id))
            ->map(function (int $id) use ($products) {
                $product = $products[$id];

                $price = null;
                if ($product->price_min !== null) {
                    $price = __('site.products.from').' '.$product->price_min
                        .($product->price_max !== null && $product->price_max != $product->price_min ? ' – '.$product->price_max : '')
                        .' '.$product->currency;
                }

                return [
                    'id' => $id,
                    'slug' => $product->slug,
                    'name' => $product->name,
                    'url' => localized_route('products.show', ['slug' => $product->slug]),
                    'category' => $product->category?->name,
                    'moq' => $product->moq,
                    'price' => $price,
                ];
            })
            ->values();

        return response()->json($items);
    }
}
