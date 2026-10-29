@extends('layouts.app')

@section('content')
<section class="section">
    <div class="container container-narrow">
        <div class="magnet-card">
            <h1>{{ $magnet->title }}</h1>

            @if($magnet->description)
                <p class="magnet-description">{{ $magnet->description }}</p>
            @endif

            {{-- 提交成功提示（蜜罐命中也走同一提示，不对机器人暴露差异） --}}
            @if(session('magnet_success'))
                <p class="form-status form-status--success">{{ session('magnet_success') }}</p>
            @endif

            {{-- 校验错误提示 --}}
            @if($errors->any())
                <p class="form-status form-status--error">{{ $errors->first() }}</p>
            @endif

            <form method="POST" action="{{ url()->current() }}">
                @csrf

                {{-- honeypot：人类不可见，机器人填了即记 spam --}}
                <div class="hp-field" aria-hidden="true">
                    <label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label>
                </div>

                <div class="form-field">
                    <label for="magnet-email">{{ __('front.email_label') }} *</label>
                    <input id="magnet-email" type="email" name="email" required maxlength="191"
                           placeholder="{{ __('front.email_ph') }}" value="{{ old('email') }}">
                </div>

                <button type="submit" class="btn btn-primary btn-lg">{{ __('front.button') }}</button>

                <p class="form-note">{{ __('front.note') }}</p>
            </form>
        </div>
    </div>
</section>
@endsection
