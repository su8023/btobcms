<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Support\Seo;
use Illuminate\Http\Request;


/**
 * 行业落地页：每个行业一个独立获客页（定制导语 + 能力清单 + FAQ + 询盘表单）。
 * 行业数据驱动自 config/industries.php，增删行业自动同步路由与导航。
 */
class IndustryController extends Controller
{
    public function index()
    {
        $industries = config('industries');
        $locale = app()->getLocale();

        $cards = collect($industries)->map(fn (array $industry) => [
            'slug' => $industry['slug'],
            'name' => $industry['name'][$locale] ?? $industry['name']['en'],
            'icon' => $industry['icon'],
        ])->values();

        return view('industries.index', [
            'industries' => $cards,
            // TDK 统一走 Seo::page（后台设置 → 语言包 → 配置），带行业关键词与品牌词
            'seo' => Seo::page('industries.index'),
        ]);
    }

    public function show(Request $request, string $slug)
    {
        $industry = collect(config('industries'))->firstWhere('slug', $slug);

        if ($industry === null) {
            abort(404);
        }

        $locale = app()->getLocale();
        $name = $industry['name'][$locale] ?? $industry['name']['en'];

        return view('industries.show', [
            'industry' => $industry,
            'industryName' => $name,
            'seo' => [
                'title' => __('industries.show_title', ['name' => $name]),
                'description' => __('industries.show_description', ['name' => $name]),
                'canonical' => localized_route('industries.show', ['slug' => $slug]),
                'alternates' => locale_alternates('industries.show', ['slug' => $slug]),
                'og' => [
                    'title' => __('industries.show_title', ['name' => $name]),
                    'description' => __('industries.show_description', ['name' => $name]),
                    'type' => 'website',
                    'url' => localized_route('industries.show', ['slug' => $slug]),
                ],
            ],
            // Service（实体）+ BreadcrumbList（层级路径，Google 富媒体结果友好）
            'jsonLd' => [
                [
                    '@context' => 'https://schema.org',
                    '@type' => 'Service',
                    'name' => __('industries.show_title', ['name' => $name]),
                    'description' => __('industries.show_description', ['name' => $name]),
                    'provider' => ['@type' => 'Organization', 'name' => config('site.name')],
                    'areaServed' => 'Worldwide',
                ],
                [
                    '@context' => 'https://schema.org',
                    '@type' => 'BreadcrumbList',
                    'itemListElement' => [
                        ['@type' => 'ListItem', 'position' => 1, 'name' => __('site.nav.home'), 'item' => localized_route('home')],
                        ['@type' => 'ListItem', 'position' => 2, 'name' => __('site.nav.industries'), 'item' => localized_route('industries.index')],
                        ['@type' => 'ListItem', 'position' => 3, 'name' => $name],
                    ],
                ],
            ],
        ]);
    }
}
