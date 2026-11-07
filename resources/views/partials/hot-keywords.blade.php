@if(! empty($hotChips))
    {{-- 热搜词：点击直达对应产品详情 --}}
    <div class="chip-row">
        <span class="section-count">{{ __('site.home.hot') }}</span>
        @foreach($hotChips as $chip)
            <a class="chip chip-link" href="{{ $chip['url'] }}">{{ $chip['label'] }}</a>
        @endforeach
    </div>
@endif
