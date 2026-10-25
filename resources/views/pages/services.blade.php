@extends('layouts.app')

@section('content')
<section class="page-head">
    <div class="container">
        <h1>{!! __('services.title') !!}</h1>
        <p>{!! __('services.subtitle') !!}</p>
    </div>
</section>

<section class="section">
    <div class="container">
        <div class="section-head">
            <h2>{{ __('services.section_title') }}</h2>
        </div>
        <div class="grid grid-2">
            @foreach($services as $i => $service)
                <article class="feature service-card" id="service-{{ $i + 1 }}">
                    <div class="feature__icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $service['icon'] }}"/></svg>
                    </div>
                    <h3>{{ $service['title'] }}</h3>
                    <p>{{ $service['text'] }}</p>
                </article>
            @endforeach
        </div>

        <div class="cta-band-inline">
            <a href="{{ localized_route('contact') }}" class="btn btn-primary btn-lg">{{ __('site.cta.quote') }}</a>
        </div>
    </div>
</section>

<section class="section section--alt" aria-label="{{ __('site.home.process_title') }}">
    <div class="container">
        <div class="section-head">
            <div>
                <span class="section-eyebrow">{{ __('site.home.process_eyebrow') }}</span>
                <h2>{{ __('site.home.process_title') }}</h2>
            </div>
        </div>
        <ol class="process">
            @foreach(__('site.home.process_steps') as $step)
                <li class="process-step">
                    <span class="process-step__num">{{ $loop->iteration }}</span>
                    <h3>{{ $step['title'] }}</h3>
                    <p>{{ $step['text'] }}</p>
                </li>
            @endforeach
        </ol>
    </div>
</section>

{{-- 服务页询盘转化点：看完服务直接发起询盘，无需跳转联系页 --}}
<section class="section" id="rfq">
    <div class="container container--narrow">
        <div class="section-head">
            <div>
                <span class="section-eyebrow">{{ __('site.cta.quote') }}</span>
                <h2>{{ __('site.home.form_card_title') }}</h2>
            </div>
        </div>
        <div class="panel-card">
            @include('partials.inquiry-form', ['source' => 'services'])
        </div>
    </div>
</section>

<section class="cta-band">
    <div class="container">
        <h2>{{ __('site.cta.band_title') }}</h2>
        <p>{{ config('site.contact.email') }} · {{ config('site.contact.phone') }}</p>
        <a href="{{ localized_route('contact') }}" class="btn btn-invert btn-lg">{{ __('site.cta.inquiry') }}</a>
    </div>
</section>
@endsection
