@extends('layouts.app')

@section('content')
<section class="section">
    <div class="container">
        <div class="section-head">
            <h1>{{ __('site.nav.contact') }}</h1>
        </div>
        <div class="contact-layout">
            <div class="contact-info">
                <h2>{{ site_setting('site.name', config('site.name')) }}</h2>
                <ul class="footer-links">
                    <li>✉ {{ site_setting('site.contact.email', config('site.contact.email')) }}</li>
                    <li>☎ {{ site_setting('site.contact.phone', config('site.contact.phone')) }}</li>
                    <li>📍 {{ site_setting('site.contact.address', config('site.contact.address')) }}</li>
                </ul>
                <p>{{ __('site.brand_slogan') }}</p>
                <div class="contact-how">
                    <h3>{{ __('site.contact_how_title') }}</h3>
                    <p>{{ __('site.contact_how') }}</p>
                    <h3>{{ __('site.contact_hours_title') }}</h3>
                    <p>{{ __('site.contact_hours') }}</p>
                    <h3>{{ __('site.contact_privacy_title') }}</h3>
                    <p>{{ __('site.contact_privacy') }}</p>
                </div>
            </div>
            <div class="contact-form">
                @include('partials.inquiry-form', ['source' => 'contact'])
            </div>
        </div>
    </div>
</section>
@endsection
