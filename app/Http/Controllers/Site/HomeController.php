<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Post;
use App\Models\Product;
use App\Support\Seo;

class HomeController extends Controller
{
    public function index()
    {
        $locale = app()->getLocale();

        // 纯线索站：行业卡片是首页核心导航（config 驱动，增删行业自动同步）
        $industries = collect(config('industries'))->map(fn (array $industry) => [
            'slug' => $industry['slug'],
            'name' => $industry['name'][$locale] ?? $industry['name']['en'],
            'icon' => $industry['icon'],
        ])->values();

        // 产品/分类区块保留但内容为空时整段隐藏（买家后期可随时恢复目录模式）
        $rootCategories = Category::query()
            ->whereNull('parent_id')
            ->where('status', true)
            ->orderBy('sort_order')
            ->get()
            ->each(function (Category $category) {
                $category->products_count = $category->subtreeProductsCount();
            });

        $latestProducts = Product::published()
            ->with('category')
            ->orderByDesc('id')
            ->take(8)
            ->get();

        $latestPosts = Post::published()
            ->orderByDesc('published_at')
            ->take(3)
            ->get();

        // 案例研究（problem–solution–results 型内容是工业买家的关键信任信号），无内容时整段隐藏
        $caseStudies = Post::published()
            ->where('type', 'case')
            ->orderByDesc('published_at')
            ->take(3)
            ->get();

        $productCount = $latestProducts->count() > 0 ? Product::published()->count() : 0;

        return view('home', [
            'industries' => $industries,
            'rootCategories' => $rootCategories,
            'latestProducts' => $latestProducts,
            'latestPosts' => $latestPosts,
            'caseStudies' => $caseStudies,
            'stats' => [
                'products' => $productCount,
                'industries' => $industries->count(),
            ],
            'seo' => Seo::page('home'),
            // GEO：Organization（实体权威）→ WebSite（有目录时含站内搜索）→ ItemList（行业/产品清单供 AI 引用）
            'jsonLd' => [
                $this->organizationJsonLd(),
                $this->webSiteJsonLd($productCount > 0),
                $this->itemListJsonLd($industries, $latestProducts),
            ],
        ]);
    }

    private function organizationJsonLd(): array
    {
        $url = localized_route('home');

        return [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            '@id' => $url.'/#organization',
            'name' => site_setting('site.name', config('site.name')),
            'url' => $url,
            // Google 富媒体要求 logo 为可解析图片，光栅 ≥112px 优先
            'logo' => $url.'/images/logo.png',
            'description' => __('site.home.hero_sub'),
            // E-E-A-T：社交资料实体关联（后台 site.social_links 每行一个 URL）
            ...($this->socialLinks() ? ['sameAs' => $this->socialLinks()] : []),
            'contactPoint' => [
                '@type' => 'ContactPoint',
                'contactType' => 'sales',
                'email' => site_setting('site.contact.email', config('site.contact.email')),
                'telephone' => site_setting('site.contact.phone', config('site.contact.phone')),
                'availableLanguage' => ['en', 'es', 'ar', 'ru', 'pt', 'fr', 'de', 'it'],
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function socialLinks(): array
    {
        $raw = (string) site_setting('site.social_links', '');

        return array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $raw) ?: [])));
    }

    private function webSiteJsonLd(bool $withSearch): array
    {
        $url = localized_route('home');

        $jsonLd = [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            '@id' => $url.'/#website',
            'name' => site_setting('site.name', config('site.name')),
            'url' => $url,
            'inLanguage' => site_locales(),
        ];

        // 站内搜索动作仅在有产品目录时声明，避免空目录下的失效结构
        if ($withSearch) {
            $jsonLd['potentialAction'] = [
                '@type' => 'SearchAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => localized_route('products.index').'?q={search_term_string}',
                ],
                'query-input' => 'required name=search_term_string',
            ];
        }

        return $jsonLd;
    }

    // 有产品时列产品；纯线索模式下列行业落地页（同样是 AI 可引用的权威清单）
    private function itemListJsonLd($industries, $latestProducts): array
    {
        $url = localized_route('home');

        if ($latestProducts->isNotEmpty()) {
            return [
                '@context' => 'https://schema.org',
                '@type' => 'ItemList',
                'name' => __('site.home.latest_products'),
                'itemListElement' => $latestProducts->map(fn (Product $p, int $i) => [
                    '@type' => 'ListItem',
                    'position' => $i + 1,
                    'url' => localized_route('products.show', ['slug' => $p->slug]),
                    'name' => $p->name,
                ])->all(),
            ];
        }

        return [
            '@context' => 'https://schema.org',
            '@type' => 'ItemList',
            'name' => __('site.home.industries_title'),
            'itemListElement' => $industries->take(12)->map(fn (array $industry, int $i) => [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'url' => localized_route('industries.show', ['slug' => $industry['slug']]),
                'name' => $industry['name'],
            ])->all(),
        ];
    }
}
