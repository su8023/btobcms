<x-filament-widgets::widget>
    <div class="wl">
        <div class="wl__head">
            <h3 class="wl__title">
                {{ $done ? '✅ 开站清单全部完成——进入日常节奏' : '🚀 新手开始清单' }}
            </h3>
            <span class="wl__count">{{ $doneCount }}/{{ $total }} 已完成</span>
        </div>

        @if ($done)
            <p class="wl__allset">每天：看首页新询盘 → 处理「线索雷达」热线 → 按 GSC 看板三个榜单优化内容 → 周五看「AI 引流」趋势。</p>
        @else
            <ul class="wl__list">
                @foreach ($items as $item)
                    <li class="wl__item">
                        <span class="wl__mark {{ $item['done'] ? 'wl__mark--done' : 'wl__mark--todo' }}">
                            @if ($item['done'])
                                {{-- 内联尺寸：SVG 无 width/height 会撑到 ~300px（此前巨型对勾的根因） --}}
                                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.7 5.3a1 1 0 010 1.4l-8 8a1 1 0 01-1.4 0l-4-4a1 1 0 111.4-1.4L8 12.6l7.3-7.3a1 1 0 011.4 0z" clip-rule="evenodd"/></svg>
                            @endif
                        </span>
                        <div class="wl__text">
                            <div class="wl__item-title {{ $item['done'] ? 'wl__item-title--done' : '' }}">{{ $item['title'] }}</div>
                            <div class="wl__hint">{{ $item['hint'] }}</div>
                        </div>
                        @if (! $item['done'] && $item['url'])
                            <a href="{{ $item['url'] }}" class="wl__btn">{{ $item['url_label'] }}</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-filament-widgets::widget>
