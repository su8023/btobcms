@php
    /**
     * 品牌 logo 标记。
     * 优先级：public/images/logo.{svg,png,jpg,webp}（放文件即生效，运营自助换标）→ 内置「Workshop」标记。
     * 内置标记：六角螺母 + 橙色核心——工业语义直白，currentColor 描边自动适配导航（深底）/页脚（浅底）。
     */
    $logoUrl = null;
    foreach (['svg', 'png', 'jpg', 'jpeg', 'webp'] as $ext) {
        if (is_file(public_path("images/logo.{$ext}"))) {
            $logoUrl = asset("images/logo.{$ext}").'?v='.filemtime(public_path("images/logo.{$ext}"));
            break;
        }
    }
@endphp
@if($logoUrl)
    <img src="{{ $logoUrl }}" alt="" class="brand-mark-img" width="30" height="30">
@else
    <svg class="brand-mark-svg" viewBox="0 0 32 32" fill="none" aria-hidden="true">
        <path d="M16 2.4 27.6 9.1v13.8L16 29.6 4.4 22.9V9.1L16 2.4Z" stroke="currentColor" stroke-width="2.7" stroke-linejoin="round"/>
        <circle cx="16" cy="16" r="4.5" fill="#d95d18"/>
    </svg>
@endif
