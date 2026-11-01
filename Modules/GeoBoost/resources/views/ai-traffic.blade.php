<div class="fi-page min-w-0">
    <x-filament-panels::page>
        {{-- ===== 指标卡：图标 + 大数字 ===== --}}
        <div class="plugin-stats-grid">
            <x-plugin-stat icon="heroicon-o-bolt" tone="primary"
                label="近 {{ $days }} 天 AI 引擎来访" value="{{ number_format($total) }}" />
            <x-plugin-stat icon="heroicon-o-calendar-days" tone="gray" label="近 7 天">
                {{ number_format($last7) }}
                @if ($last7 > 0)
                    <span class="ml-1 text-sm {{ $trend >= 0 ? 'text-success-500' : 'text-danger-500' }}">{{ $trend >= 0 ? '↑' : '↓' }}{{ abs($trend) }}%</span>
                @endif
            </x-plugin-stat>
            <x-plugin-stat icon="heroicon-o-globe-alt" tone="gray"
                label="涉及引擎" value="{{ $byEngine->count() }}" />
        </div>

        <div class="plugin-stack mt-6"><div class="plugin-cols-2">
            <x-filament::section>
                <x-slot name="heading">按引擎</x-slot>
                @if ($byEngine->isEmpty())
                    <x-plugin-empty icon="heroicon-o-cpu-chip" title="暂无来访记录"
                        description="当 AI 搜索引擎（ChatGPT/Perplexity 等）的访客点进来，这里开始记录。" />
                @else
                    <ul class="divide-y">
                        @foreach ($byEngine as $row)
                            <li class="flex items-center justify-between py-2 text-sm">
                                <span class="font-medium">{{ $row->engine }}</span>
                                <span class="font-mono tabular-nums">{{ number_format($row->total) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">热门落地页（近 {{ $days }} 天）</x-slot>
                @if ($topPaths->isEmpty())
                    <x-plugin-empty icon="heroicon-o-document-chart-bar" title="暂无数据"
                        description="AI 引擎访客点击的落地页会按热度排在这里。" />
                @else
                    <ul class="divide-y">
                        @foreach ($topPaths as $row)
                            <li class="flex items-center justify-between gap-4 py-2 text-sm">
                                <span class="min-w-0 truncate font-mono text-gray-600 dark:text-gray-300">/{{ $row->path }}</span>
                                <span class="flex-none font-mono tabular-nums">{{ number_format($row->total) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-filament::section>
        </div></div>

        <x-filament::section class="mt-6">
            <x-slot name="heading">每日来访（近 {{ $days }} 天）</x-slot>
            @if ($daily->isEmpty())
                <x-plugin-empty icon="heroicon-o-chart-bar" title="暂无数据"
                    description="有来访后这里展示每日趋势柱状图。" />
            @else
                @php($max = $daily->max('total'))
                <div class="overflow-x-auto">
                    <div class="flex min-w-full items-end gap-1 h-32 pt-4">
                        @foreach ($daily as $row)
                            <div class="min-w-2 flex-1 bg-primary-500/70 rounded-t"
                                style="height: {{ max(4, (int) round($row->total / max($max, 1) * 100)) }}%"
                                title="{{ $row->day }}：{{ $row->total }}"></div>
                        @endforeach
                    </div>
                </div>
                <div class="flex justify-between text-xs text-gray-400 mt-1">
                    <span>{{ $daily->first()->day }}</span>
                    <span>{{ $daily->last()->day }}</span>
                </div>
            @endif
        </x-filament::section>

        <x-filament::section class="mt-6">
            <x-slot name="heading">最近来访</x-slot>
            @if ($recent->isEmpty())
                <x-plugin-empty icon="heroicon-o-clock" title="暂无数据"
                    description="最近一次 AI 引擎来访会实时列在这里。" />
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500 border-b">
                                <th class="py-2">时间</th>
                                <th>引擎</th>
                                <th>落地页</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($recent as $visit)
                                <tr class="border-b">
                                    <td class="py-2 whitespace-nowrap tabular-nums">{{ $visit->created_at->format('m-d H:i') }}</td>
                                    <td><span class="fi-badge fi-badge-color-gray">{{ $visit->engine }}</span></td>
                                    <td class="max-w-xs truncate font-mono text-gray-600 dark:text-gray-300">/{{ $visit->path }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    </x-filament-panels::page>
</div>
