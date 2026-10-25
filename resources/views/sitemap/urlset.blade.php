<?php echo '<?xml version="1.0" encoding="UTF-8"?>'; ?>

<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"
        xmlns:xhtml="http://www.w3.org/1999/xhtml">
@foreach($urls as $entry)
    <url>
        <loc>{{ htmlspecialchars($entry['url'], ENT_XML1) }}</loc>
        @if(!empty($entry['lastmod']))
            <lastmod>{{ $entry['lastmod'] }}</lastmod>
        @endif
        @foreach($entry['alternates'] as $altLocale => $href)
            <xhtml:link rel="alternate" hreflang="{{ $altLocale }}" href="{{ htmlspecialchars($href, ENT_XML1) }}"/>
        @endforeach
    </url>
@endforeach
</urlset>
