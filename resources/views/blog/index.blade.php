@extends('layouts.app')

@section('content')
<section class="page-head">
    <div class="container">
        <h1>{{ __('site.blog.title') }}</h1>
        <p>{{ __('site.blog.subtitle') }}</p>
    </div>
</section>

<section class="section">
    <div class="container">
        @if($posts->isEmpty())
            <p class="empty-state">—</p>
        @else
            <div class="grid grid-3">
                @foreach($posts as $post)
                    <a class="post-card" href="{{ localized_route('blog.show', ['slug' => $post->slug]) }}">
                        <span class="post-card__date">{{ optional($post->published_at)->format('Y-m-d') }}</span>
                        <h3>{{ $post->title }}</h3>
                        <p>{{ \Illuminate\Support\Str::limit(strip_tags((string) $post->excerpt), 140) }}</p>
                        <span class="post-card__more">{{ __('site.blog.read_more') }} →</span>
                    </a>
                @endforeach
            </div>
            {{ $posts->links('partials.pagination') }}
        @endif
    </div>
</section>
@endsection
