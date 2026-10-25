<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Support\Seo;

class PageController extends Controller
{
    public function contact()
    {
        return view('pages.contact', [
            'seo' => Seo::page('contact'),
        ]);
    }

    public function about()
    {
        // GEO：FAQPage 结构化数据 + 页面 FAQ（AI 引擎优先引用问答格式内容）
        $faq = collect(__('site.faq.items'))->map(fn (array $item) => [
            'q' => $item['q'],
            'a' => $item['a'],
        ]);

        return view('pages.about', [
            'seo' => Seo::page('about'),
            'faq' => $faq,
            // 注意：必须是图的列表（单个图也要包一层数组），布局按列表遍历输出
            'jsonLd' => $faq->isNotEmpty() ? [
                [
                    '@context' => 'https://schema.org',
                    '@type' => 'FAQPage',
                    'mainEntity' => $faq->map(fn (array $item) => [
                        '@type' => 'Question',
                        'name' => $item['q'],
                        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['a']],
                    ])->values()->all(),
                ],
            ] : null,
        ]);
    }

    /**
     * 询盘篮（RFQ）：列出已选产品并统一提交询盘，页面本身不收录。
     */
    public function basket()
    {
        return view('basket', [
            'noindex' => true,
        ]);
    }
    public function privacy()
    {
        return view('pages.legal', [
            'kind' => 'privacy',
            'seo' => Seo::page('privacy'),
        ]);
    }

    public function terms()
    {
        return view('pages.legal', [
            'kind' => 'terms',
            'seo' => Seo::page('terms'),
        ]);
    }
}
