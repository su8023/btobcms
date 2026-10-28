@php
    /** @var \App\Models\Post $post */
    $faqs = \Modules\GeoBoost\Models\GeoFaq::query()
        ->where('faqable_type', \App\Models\Post::class)
        ->where('faqable_id', $post->id)
        ->where('locale', app()->getLocale())
        ->orderBy('sort')
        ->get();
@endphp

@if ($faqs->isNotEmpty())
    <section class="faq-block" aria-labelledby="faq-heading" style="margin-top:2rem">
        <h2 id="faq-heading">{{ __('geoboost::faq.title') }}</h2>
        @foreach ($faqs as $faq)
            <details @if($loop->first) open @endif>
                <summary>{{ $faq->question }}</summary>
                <p>{{ $faq->answer }}</p>
            </details>
        @endforeach
    </section>

    @php
        $mainEntity = $faqs->map(fn ($f) => [
            '@type' => 'Question',
            'name' => $f->question,
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f->answer],
        ])->values()->all();
    @endphp
    <script type="application/ld+json">{!! json_encode([
        '@context' => 'https://schema.org',
        '@type' => 'FAQPage',
        'mainEntity' => $mainEntity,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !!}</script>
@endif
