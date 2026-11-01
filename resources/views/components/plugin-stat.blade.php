{{--
    插件页统一指标卡（KPI Tile）
    用法：
        <x-plugin-stat icon="heroicon-o-chart-bar" tone="primary"
            label="近 28 天总点击" value="1,204" />
        value 也可用插槽塞趋势小字：<x-slot>{{ … }}</x-slot>

    tone: gray | primary | success | warning | danger（决定图标底色与数字色）
    样式：public/css/admin-plugins.css（Filament 预编译 CSS 不含应用 Tailwind 类）
--}}
@props([
    'label' => '',
    'value' => null,
    'sub' => '',
    'tone' => 'gray',
    'icon' => '',
])

<div class="plugin-stat plugin-stat--{{ $tone }}" {{ $attributes }}>
    @if ($icon !== '')
        <div class="plugin-stat__icon">
            <x-filament::icon :icon="$icon" />
        </div>
    @endif
    <div class="plugin-stat__body">
        <div class="plugin-stat__label">{{ $label }}</div>
        <div class="plugin-stat__value">
            @if ($value !== null)
                {{ $value }}
            @else
                {{ $slot }}
            @endif
        </div>
        @if ($sub !== '')
            <div class="plugin-stat__sub">{{ $sub }}</div>
        @endif
    </div>
</div>
