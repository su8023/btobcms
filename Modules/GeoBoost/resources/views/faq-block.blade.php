@php
    /** @var \App\Models\Post $post */
    // FAQ 变更少而读多：5 分钟缓存，生成/删除/文章删除时失效。
    // 注意：database cache 白名单反序列化（禁存 Eloquent 对象），只缓存标量数组。
    $faqs = cache()->remember(
        'geoboost.faqs.'.$post->id.'.'.app()->getLocale(),
        now()->addMinutes(5),
        fn () => \Modules\GeoBoost\Models\GeoFaq::query()
            ->where('faqable_type', \App\Models\Post::class)
            ->where('faqable_id', $post->id)
            ->where('locale', app()->getLocale())
            ->orderBy('sort')
            ->get(['question', 'answer'])
            ->map(fn ($f) => ['q' => (string) $f->question, 'a' => (string) $f->answer])
            ->all(),
    );
@endphp

@if ($faqs !== [])
    <section class="faq-block" aria-labelledby="faq-heading" style="margin-top:2rem">
        <h2 id="faq-heading">{{ __('geoboost::faq.title') }}</h2>
        @foreach ($faqs as $faq)
            <details @if($loop->first) open @endif>
                <summary>{{ $faq['q'] }}</summary>
                <p>{{ $faq['a'] }}</p>
            </details>
        @endforeach
    </section>

    @php
        $mainEntity = collect($faqs)->map(fn ($f) => [
            '@type' => 'Question',
            'name' => $f['q'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['a']],
        ])->values()->all();
    @endphp
    <script type="application/ld+json">{!! json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => $mainEntity,
    ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
@endif
