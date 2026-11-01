{{--
    插件页统一空状态（数据未积累/未配置时的占位）
    用法：
        <x-plugin-empty icon="heroicon-o-chart-bar"
            title="暂无数据"
            description="当 AI 搜索引擎的访客点进来，这里开始记录。" />
    description 也可用插槽（需要放链接/富文本时）：<x-slot:description>…</x-slot:description>
    样式：public/css/admin-plugins.css
--}}
@props([
    'title' => '',
    'description' => null,
    'icon' => 'heroicon-o-inbox',
])

<div class="plugin-empty" {{ $attributes }}>
    <div class="plugin-empty__icon">
        <x-filament::icon :icon="$icon" />
    </div>
    @if ($title !== '')
        <div class="plugin-empty__title">{{ $title }}</div>
    @endif
    <div class="plugin-empty__desc">
        @if ($description !== null)
            {{ $description }}
        @else
            {{ $slot }}
        @endif
    </div>
</div>
