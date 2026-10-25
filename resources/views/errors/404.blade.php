@extends('layouts.app')

@section('content')
<section class="section section--tall">
    <div class="container container--narrow notfound">
        <p class="notfound__code" aria-hidden="true">404</p>
        <h1>{{ __('site.notfound.title') }}</h1>
        <p>{{ __('site.notfound.text') }}</p>
        <div class="hero-actions" style="margin-top:24px">
            <a href="{{ localized_route('home') }}" class="btn btn-primary btn-lg">{{ __('site.notfound.home') }}</a>
            <a href="{{ localized_route('industries.index') }}" class="btn btn-ghost-dark btn-lg">{{ __('site.nav.industries') }}</a>
            <a href="{{ localized_route('contact') }}" class="btn btn-ghost-dark btn-lg">{{ __('site.cta.inquiry') }}</a>
        </div>
    </div>
</section>
@endsection

@push('head')
    <meta name="robots" content="noindex">
@endpush
