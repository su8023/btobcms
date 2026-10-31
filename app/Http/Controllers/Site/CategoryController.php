<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\Product;
use App\Support\Seo;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function show(Request $request, string $slug)
    {
        $category = Category::query()
            ->where('slug', $slug)
            ->where('status', true)
            ->with('attrSet')
            ->firstOrFail();

        // 物化路径取全部后代分类
        $categoryIds = Category::query()
            ->where('path', 'like', $category->path.'/%')
            ->pluck('id')
            ->push($category->getKey());

        $facets = $this->facets($category);

        // 归一化筛选参数：?f=abc（标量）或 ?f[code][]=x（数组值）都不能让请求 500
        $filters = $request->query('f');

        if (! is_array($filters)) {
            $filters = [];
        }

        $products = Product::published()
            ->whereIn('category_id', $categoryIds)
            ->when($filters !== [], function ($query) use ($filters, $facets) {
                foreach ($facets as $facet) {
                    $value = $filters[$facet->code] ?? null;

                    // 只接受标量筛选值：数组/对象会让 PDO 绑定直接抛异常
                    if (! is_scalar($value) || (string) $value === '') {
                        continue;
                    }

                    // JSON 路径必须绑定传参，绝不能把 code 拼进 SQL 字符串
                    // （code 是后台可编辑字段，拼进去等于给了注入入口）
                    $query->whereRaw(
                        'json_extract(attrs, ?) = ?',
                        ['$.'.$facet->code, (string) $value]
                    );
                }
            })
            ->with('category')
            ->orderByDesc('id')
            ->paginate(12)
            ->withQueryString();

        // 子分类计数同样按子树汇总（与首页/导航一致）
        $subcategories = $category->children()
            ->where('status', true)
            ->orderBy('sort_order')
            ->get()
            ->each(function (Category $child) {
                $child->products_count = $child->subtreeProductsCount();
            });

        $routeParams = ['slug' => $category->slug];

        return view('categories.show', [
            'category' => $category,
            'subcategories' => $subcategories,
            'products' => $products,
            'facets' => $facets,
            'activeFilters' => $filters,
            'seo' => Seo::for($category, 'categories.show', $routeParams),
            'jsonLd' => [
                '@context' => 'https://schema.org',
                '@type' => 'BreadcrumbList',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => __('site.nav.home'), 'item' => localized_route('home')],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => $category->name, 'item' => localized_route('categories.show', $routeParams)],
                ],
            ],
        ]);
    }

    /**
     * 可筛选属性 = 分类属性模板中的枚举型且声明为可筛选。
     *
     * @return \Illuminate\Support\Collection<int, Attribute>
     */
    private function facets(Category $category)
    {
        if (! $category->attrSet) {
            return collect();
        }

        return $category->attrSet
            ->attributes()
            ->where('is_filterable', true)
            ->where('type', 'enum')
            ->orderBy('sort_order')
            ->get();
    }
}
