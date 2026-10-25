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
                'author' => ['@type' => 'Organization', 'name' => config('site.name')],
                'mainEntityOfPage' => localized_route('blog.show', $routeParams),
            ],
        ]);
    }
}
