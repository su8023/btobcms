@extends('layouts.app')

@section('content')
<section class="page-head">
    <div class="container">
        <h1>{{ __("legal.{$kind}.title") }}</h1>
        <p>{{ __("legal.{$kind}.sub") }}</p>
    </div>
</section>

<section class="section">
    <div class="container container--narrow legal-body">
        @foreach(__('legal.'.$kind.'.sections') as $section)
            <h2>{{ $section['h'] }}</h2>
            <p>{{ $section['p'] }}</p>
        @endforeach
        <p class="legal-contact">{!! __('legal.contact_line', ['email' => site_setting('site.contact.email', config('site.contact.email'))]) !!}</p>
    </div>
</section>
@endsection
