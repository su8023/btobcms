@extends('layouts.app')

@section('content')
<section class="section">
    <div class="container container-narrow">
        <div class="section-head">
            <h1>{{ __('site.basket.title') }}</h1>
        </div>

        <div id="basket-items" class="basket-list">
            <p class="empty-state">{{ __('site.basket.empty') }}</p>
        </div>

        <div class="contact-form basket-form-wrap">
            <p class="form-note">{{ __('site.basket.submit_hint') }}</p>
            @include('partials.inquiry-form', ['source' => 'basket'])
        </div>
    </div>
</section>
@endsection
