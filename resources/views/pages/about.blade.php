@extends('layouts.app')

@section('content')
<section class="section">
    <div class="container container-narrow">
        <div class="section-head">
            <h1>{{ __('site.nav.about') }}</h1>
        </div>
        <div class="article">
            <h2>{{ config('site.name') }}</h2>
            <p>{{ __('site.home.hero_sub') }}</p>
            <h3>Factory & Quality Control</h3>
            <p>
                {{ config('site.name') }} operates production lines with in-house quality control from raw material
                inspection to final packaging. Our QC team performs AQL-standard inspections for every order.
            </p>
            <h3>OEM / ODM Support</h3>
            <p>
                Custom specifications, private labeling and packaging are supported. Share your drawings or samples
                and our engineering team will respond with a feasibility review and quotation.
            </p>
            <h3>Global Delivery</h3>
            <p>
                We ship worldwide by sea, air and express, with EXW / FOB / CIF terms. Contact us for a quote on
                your destination port.
            </p>
        </div>

        @if(!empty($faq) && $faq->isNotEmpty())
            <h2>{{ __('site.faq.title') }}</h2>
            <div class="faq-list">
                @foreach($faq as $item)
                    <details class="faq-item">
                        <summary>{{ $item['q'] }}</summary>
                        <p class="faq-item__a">{{ $item['a'] }}</p>
                    </details>
                @endforeach
            </div>
        @endif

        <a href="{{ localized_route('contact') }}" class="btn btn-primary">{{ __('site.cta.quote') }}</a>
    </div>
</section>
@endsection
