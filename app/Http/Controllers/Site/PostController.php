<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Support\Seo;

class PostController extends Controller
{
    public function index()
    {
        $posts = Post::published()
            ->orderByDesc('published_at')
            ->paginate(10)
            ->withQueryString();

        return view('blog.index', [
            'posts' => $posts,
            'seo' => Seo::page('blog.index'),
        ]);
    }

    public function show(string $slug)
    {
        $post = Post::published()
            ->where('slug', $slug)
            ->firstOrFail();

        $post->increment('views');

        $routeParams = ['slug' => $post->slug];

        return view('blog.show', [
            'post' => $post,
            'seo' => Seo::for($post, 'blog.show', $routeParams),
            'jsonLd' => [
                '@context' => 'https://schema.org',
                '@type' => 'Article',
                'headline' => $post->title,
                'description' => (string) ($post->excerpt ?? ''),
                'datePublished' => optional($post->published_at)->toIso8601String(),
                'dateModified' => $post->updated_at->toIso8601String(),
                'author' => ['@type' => 'Organization', 'name' => site_name()],
                'mainEntityOfPage' => localized_route('blog.show', $routeParams),
            ],
        ]);
    }

    /**
     * 草稿/待审文章的签名预览（AI 写作室「预览草稿」按钮）。
     *
     * 与 show() 的三点差别：不限发布状态（草稿只能这样看）、不增加浏览量、
     * 输出 noindex 防搜索引擎收录预览链接。签名由路由 signed 中间件校验，
     * 无签名或过期一律 403——未发布内容不会泄漏给无链接的访客与爬虫。
     */
    public function preview(string $slug)
    {
        $post = Post::query()
            ->where('slug', $slug)
            ->firstOrFail();

        $routeParams = ['slug' => $post->slug];

        return response()
            ->view('blog.show', [
                'post' => $post,
                'seo' => Seo::for($post, 'blog.show', $routeParams),
                'jsonLd' => [], // 预览页不需要结构化数据（也不该被引擎抓取）
                'noindex' => true,
            ])
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }
}
