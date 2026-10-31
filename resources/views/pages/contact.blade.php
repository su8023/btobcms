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
                    <li>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></svg>
                        {{ site_setting('site.contact.email', config('site.contact.email')) }}
                    </li>
                    <li>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                        {{ site_setting('site.contact.phone', config('site.contact.phone')) }}
                    </li>
                    <li>
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 0 1 16 0z"/><circle cx="12" cy="10" r="3"/></svg>
                        {{ site_setting('site.contact.address', config('site.contact.address')) }}
                    </li>
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
