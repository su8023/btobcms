<div>
    <x-filament-panels::page>
        <div class="fi-section">
            <div class="grid gap-4 md:grid-cols-3">
                <div class="fi-widget p-4 rounded-xl bg-white shadow dark:bg-gray-900">
                    <div class="text-sm text-gray-500">近 {{ $days }} 天 AI 引擎来访</div>
                    <div class="text-3xl font-bold">{{ number_format($total) }}</div>
                </div>
                <div class="fi-widget p-4 rounded-xl bg-white shadow dark:bg-gray-900">
                    <div class="text-sm text-gray-500">近 7 天</div>
                    <div class="text-3xl font-bold">{{ number_format($last7) }}
                        @if ($last7 > 0)
                            <span class="text-base {{ $trend >= 0 ? 'text-success-500' : 'text-danger-500' }}">
                                {{ $trend >= 0 ? '↑' : '↓' }}{{ abs($trend) }}%
                            </span>
                        @endif
                    </div>
                </div>
                <div class="fi-widget p-4 rounded-xl bg-white shadow dark:bg-gray-900">
                    <div class="text-sm text-gray-500">涉及引擎</div>
                    <div class="text-3xl font-bold">{{ $byEngine->count() }}</div>
                </div>
            </div>
        </div>

        <div class="fi-section mt-6 grid gap-6 md:grid-cols-2">
            <x-filament::section>
                <x-slot name="heading">按引擎</x-slot>
                @if ($byEngine->isEmpty())
                    <p class="text-sm text-gray-500">暂无数据：当 AI 搜索引擎（ChatGPT/Perplexity 等）的访客点进来，这里开始记录。</p>
                @else
                    <ul class="divide-y">
                        @foreach ($byEngine as $row)
                            <li class="flex justify-between py-2 text-sm">
                                <span>{{ $row->engine }}</span>
                                <span class="font-mono">{{ number_format($row->total) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">热门落地页（近 {{ $days }} 天）</x-slot>
                @if ($topPaths->isEmpty())
                    <p class="text-sm text-gray-500">暂无数据</p>
                @else
                    <ul class="divide-y">
                        @foreach ($topPaths as $row)
                            <li class="flex justify-between gap-4 py-2 text-sm">
                                <span class="truncate font-mono">/{{ $row->path }}</span>
                                <span class="font-mono">{{ number_format($row->total) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-filament::section>
        </div>

        <x-filament::section class="mt-6">
            <x-slot name="heading">每日来访（近 {{ $days }} 天）</x-slot>
            @if ($daily->isEmpty())
                <p class="text-sm text-gray-500">暂无数据</p>
            @else
                @php($max = $daily->max('total'))
                <div class="flex items-end gap-1 h-32 pt-4">
                    @foreach ($daily as $row)
                        <div class="flex-1 bg-primary-500/70 rounded-t" style="height: {{ max(4, (int) round($row->total / max($max, 1) * 100)) }}%"
                            title="{{ $row->day }}：{{ $row->total }}"></div>
                    @endforeach
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
                <p class="text-sm text-gray-500">暂无数据</p>
            @else
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
                                <td class="py-2 whitespace-nowrap">{{ $visit->created_at->format('m-d H:i') }}</td>
                                <td><span class="fi-badge fi-badge-color-gray">{{ $visit->engine }}</span></td>
                                <td class="truncate font-mono">/{{ $visit->path }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>
    </x-filament-panels::page>
</div>
