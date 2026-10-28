@extends('layouts.app')

@section('content')
<section class="section">
    <div class="container container-narrow">
        <nav class="breadcrumb">
            <a href="{{ localized_route('home') }}">{{ __('site.nav.home') }}</a> /
            <a href="{{ localized_route('blog.index') }}">{{ __('site.nav.blog') }}</a> /
            <span>{{ $post->title }}</span>
        </nav>

        <article class="article">
            <h1>{{ $post->title }}</h1>
            <div class="article-meta">
                <time datetime="{{ optional($post->published_at)->toIso8601String() }}">{{ optional($post->published_at)->format('Y-m-d') }}</time>
            </div>
            @if($post->excerpt)
                <p class="article-excerpt">{{ $post->excerpt }}</p>
            @endif
            <div class="article-body">{!! $post->content !!}</div>

            @foreach(\App\Support\Slots::get('post_bottom') as $postBottomView)
                @include($postBottomView)
            @endforeach
        </article>

        <a class="btn btn-ghost" href="{{ localized_route('blog.index') }}">← {{ __('site.blog.back') }}</a>
    </div>
</section>
@endsection
