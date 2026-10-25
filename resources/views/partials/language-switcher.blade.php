@php($current = app()->getLocale())
<div class="lang-switch">
    @foreach(site_locales() as $locale)
        @php($href = $alternates[$locale] ?? ($locale === $current ? url()->current() : localized_route('home', [], $locale)))
        @if($locale === $current)
            <span class="lang-current">{{ strtoupper($locale) }}</span>
        @else
            <a class="lang-link" href="{{ $href }}" hreflang="{{ $locale }}"
               title="{{ config("site.native_names.{$locale}") }}">{{ strtoupper($locale) }}</a>
        @endif
    @endforeach
</div>
