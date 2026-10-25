@extends('layouts.app')

@section('content')
<section class="page-head">
    <div class="container">
        <h1>{!! __('industries.show_title', ['name' => $industryName]) !!}</h1>
        <p>{!! __('industries.show_description', ['name' => $industryName]) !!}</p>
    </div>
</section>

<section class="section">
    <div class="container">
        <h2 class="section-sub" style="font-weight:700; color:var(--ink); font-size:var(--fs-lg); margin-bottom:28px;">
            {!! __('industries.capabilities', ['name' => $industryName]) !!}
        </h2>

        <div class="grid grid-2">
            @foreach(['sourcing', 'custom', 'quality', 'logistics'] as $cap)
                <article class="feature">
                    <div class="feature__icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 13l4 4L19 7"/></svg>
                    </div>
                    <h3>{{ __('industries.capability_'.$cap.'_title') }}</h3>
                    <p>{{ __('industries.capability_'.$cap.'_text') }}</p>
                </article>
            @endforeach
        </div>

        <div class="cta-band-inline" style="margin-top:40px;">
            <a href="{{ localized_route('contact') }}" class="btn btn-primary btn-lg">
                {!! __('industries.start_project', ['name' => $industryName]) !!}
            </a>
        </div>
    </div>
</section>

{{-- 询盘表单（行业名自动带入消息） --}}
<section class="section section--alt" id="inquiry">
    <div class="container container-narrow">
        <div class="section-head">
            <div>
                <span class="section-eyebrow">{{ __('site.cta.quote') }}</span>
                <h2>{!! __('industries.form_intro', ['name' => $industryName]) !!}</h2>
            </div>
        </div>
        <div class="contact-form">
            @include('partials.inquiry-form', ['source' => 'industry', 'industryName' => $industryName])
        </div>
    </div>
</section>
@endsection
