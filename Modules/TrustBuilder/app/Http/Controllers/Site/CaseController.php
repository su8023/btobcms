<?php

namespace Modules\TrustBuilder\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Support\Str;
use Modules\TrustBuilder\Models\TrustCase;

/**
 * 案例前台页：列表（已发布按 sort）与详情（未发布 404）。
 * 详情页向 partials/seo 传 $seo（title/description 等）与
 * $jsonLd（BreadcrumbList + WebPage，按 seo.blade.php 的多图列表约定）。
 */
class CaseController extends Controller
{
    /**
     * 案例列表：已发布、按 sort 升序，分页 12 条。
     */
    public function index(): ViewContract
    {
        $cases = TrustCase::query()
            ->published()
            ->sorted()
            ->paginate(12)
            ->withQueryString();

        return view('trustbuilder::cases.index', [
            'cases' => $cases,
            // 列表页 SEO：静态页信息包（title/description 取模块语言包）
            'seo' => [
                'title' => __('trustbuilder.nav.cases').' | '.site_setting('site.name', config('site.name')),
                'description' => __('trustbuilder.front.cases_description'),
                'canonical' => localized_route('cases.index'),
                'alternates' => locale_alternates('cases.index'),
                'og' => [
                    'title' => __('trustbuilder.nav.cases'),
                    'description' => __('trustbuilder.front.cases_description'),
                    'type' => 'website',
                    'url' => localized_route('cases.index'),
                ],
            ],
        ]);
    }

    /**
     * 案例详情：未发布或不存在一律 404；
     * 附同行业相关案例（已发布，最多 3 条）。
     */
    public function show(string $slug): ViewContract
    {
        $case = TrustCase::query()
            ->published()
            ->where('slug', $slug)
            ->firstOrFail();

        $routeParams = ['slug' => $case->slug];
        $canonical = localized_route('cases.show', $routeParams);
        $siteName = (string) site_setting('site.name', config('site.name'));

        // SEO 信息包：标题 = 案例名 | 站名；描述取正文纯文本前 160 字
        $description = Str::limit(trim(strip_tags((string) $case->body)), 160);

        // JSON-LD：BreadcrumbList（首页 → 案例列表 → 当前案例）+ WebPage（含案例名）
        $jsonLd = [
            [
                '@context' => 'https://schema.org',
                '@type' => 'BreadcrumbList',
                'itemListElement' => [
                    [
                        '@type' => 'ListItem',
                        'position' => 1,
                        'name' => __('site.nav.home'),
                        'item' => localized_route('home'),
                    ],
                    [
                        '@type' => 'ListItem',
                        'position' => 2,
                        'name' => __('trustbuilder.nav.cases'),
                        'item' => localized_route('cases.index'),
                    ],
                    [
                        '@type' => 'ListItem',
                        'position' => 3,
                        'name' => (string) $case->title,
                    ],
                ],
            ],
            [
                '@context' => 'https://schema.org',
                '@type' => 'WebPage',
                'name' => (string) $case->title,
                'description' => $description,
                'url' => $canonical,
                'inLanguage' => app()->getLocale(),
                'about' => [
                    '@type' => 'Organization',
                    'name' => (string) $case->customer_name,
                ],
                'isPartOf' => ['@id' => localized_route('home').'#website'],
            ],
        ];

        return view('trustbuilder::cases.show', [
            'case' => $case,
            // 同行业相关案例：排除自身，已发布按 sort，最多 3 条；行业为空不推荐
            'relatedCases' => filled($case->industry)
                ? TrustCase::query()
                    ->published()
                    ->sorted()
                    ->where('industry', $case->industry)
                    ->whereKeyNot($case->getKey())
                    ->limit(3)
                    ->get()
                : collect(),
            'seo' => [
                'title' => $case->title.' | '.$siteName,
                'description' => $description,
                'canonical' => $canonical,
                'alternates' => locale_alternates('cases.show', $routeParams),
                'og' => [
                    'title' => (string) $case->title,
                    'description' => $description,
                    'type' => 'website',
                    'url' => $canonical,
                    'image' => $case->coverUrl() ?: asset('images/og-default.png'),
                ],
            ],
            'jsonLd' => $jsonLd,
        ]);
    }
}
