{{-- 案例列表页：已发布案例按 sort 展示（控制器保证），卡片 grid 布局复用站内 grid-3 --}}
@extends('layouts.app')

@section('content')
<section class="page-head">
    <div class="container">
        <h1>{{ __('trustbuilder.nav.cases') }}</h1>
        <p>{{ __('trustbuilder.front.cases_subtitle') }}</p>
    </div>
</section>

<section class="section">
    <div class="container">
        @if($cases->isEmpty())
            <p class="empty-state">—</p>
        @else
            <div class="grid grid-3">
                @foreach($cases as $case)
                    <a class="trust-case-card" href="{{ localized_route('cases.show', ['slug' => $case->slug]) }}">
                        <span class="trust-case-card__media">
                            @if($case->coverUrl())
                                <img src="{{ $case->coverUrl() }}" alt="{{ $case->customer_name }}" loading="lazy">
                            @endif
                        </span>
                        <span class="trust-case-card__body">
                            <span class="trust-case-card__customer">{{ $case->customer_name }}</span>
                            @if($case->industry || $case->country)
                                <span class="trust-case-card__meta">{{ collect([$case->industry, $case->country])->filter()->implode(' · ') }}</span>
                            @endif
                            <span class="trust-case-card__title">{{ $case->title }}</span>
                            <span class="trust-case-card__more">{{ __('trustbuilder.front.view_case') }} →</span>
                        </span>
                    </a>
                @endforeach
            </div>
            {{ $cases->links('partials.pagination') }}
        @endif
    </div>
</section>
@endsection
