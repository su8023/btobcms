<x-filament-widgets::widget>
    <div class="fi-widget p-4 rounded-xl bg-white shadow dark:bg-gray-900">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-base font-bold">
                @if ($done)
                    ✅ 开站清单全部完成——进入日常节奏
                @else
                    🚀 新手开始清单
                @endif
            </h3>
            <span class="text-sm text-gray-500">{{ $doneCount }}/{{ $total }} 已完成</span>
        </div>

        @if ($done)
            <p class="text-sm text-gray-500">每天：看首页新询盘 → 处理「线索雷达」热线 → 按 GSC 看板三个榜单优化内容 → 周五看「AI 引流」趋势。</p>
        @else
            <ul class="divide-y">
                @foreach ($items as $item)
                    <li class="flex items-start gap-3 py-2.5">
                        <span class="mt-0.5 {{ $item['done'] ? 'text-success-500' : 'text-gray-300' }}">
                            @if ($item['done'])
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-8 8a1 1 0 01-1.4 0l-4-4a1 1 0 111.4-1.4L8 12.6l7.3-7.3a1 1 0 011.4 0z" clip-rule="evenodd"/></svg>
                            @else
                                <span class="inline-block h-5 w-5 rounded-full border-2 border-gray-300"></span>
                            @endif
                        </span>
                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-semibold {{ $item['done'] ? 'text-gray-400 line-through' : '' }}">{{ $item['title'] }}</div>
                            <div class="text-xs text-gray-500 mt-0.5">{{ $item['hint'] }}</div>
                        </div>
                        @if (! $item['done'] && $item['url'])
                            <a href="{{ $item['url'] }}"
                               class="shrink-0 text-xs font-bold px-3 py-1.5 rounded-lg bg-primary-500 text-white hover:bg-primary-600">
                                {{ $item['url_label'] }}
                            </a>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-filament-widgets::widget>
