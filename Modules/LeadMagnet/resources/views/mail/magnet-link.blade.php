{{ __('front.mail_greeting') }}

{{ __('front.mail_intro', ['title' => $magnet->title]) }}

  [ {{ __('front.mail_button') }} ]
  {{ $url }}

{{ __('front.mail_expiry') }}

{{ __('front.mail_footer') }}

— {{ $siteName }}
