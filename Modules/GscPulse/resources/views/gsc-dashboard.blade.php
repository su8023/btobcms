<div>
    <x-filament-panels::page>
        {{-- 空态一：未连接 --}}
        @if (! $connected)
            <x-filament::section>
                <x-slot name="heading">先连接 Google Search Console</x-slot>
                <x-plugin-empty icon="heroicon-o-link"
                    title="尚未授权 Google Search Console">
                    <x-slot:description>
                        请到 <a href="{{ $connectUrl }}" class="text-primary-500 underline">「GSC 连接」页</a>
                        填写 OAuth 凭据并完成 Google 授权，之后每日 06:10 会自动回流数据（也可点右上角「立即拉取」）。
                    </x-slot:description>
                </x-plugin-empty>
            </x-filament::section>
        @elseif (! $hasData)
            {{-- 空态二：已连接但还没拉过数据 --}}
            <x-filament::section>
                <x-slot name="heading">还没有数据</x-slot>
                <x-plugin-empty icon="heroicon-o-arrow-down-tray"
                    title="连接已就绪，等待第一次拉取">
                    <x-slot:description>
                        站点属性：<span class="font-mono">{{ $siteUrl }}</span>。点击右上角「立即拉取」开始回流近 {{ $days }} 天的搜索表现数据。
                    </x-slot:description>
                </x-plugin-empty>
            </x-filament::section>
        @endif

        {{-- 概览统计：图标 + 大数字，三个指标一眼分层 --}}
        <div class="plugin-stats-grid">
            <x-plugin-stat icon="heroicon-o-cursor-arrow-rays" tone="primary"
                label="近 {{ $days }} 天总点击" value="{{ number_format($stats['clicks']) }}" />
            <x-plugin-stat icon="heroicon-o-eye" tone="gray"
                label="近 {{ $days }} 天总展现" value="{{ number_format($stats['impressions']) }}" />
            <x-plugin-stat icon="heroicon-o-chart-bar" tone="gray"
                label="平均排名"
                value="{{ $stats['position'] !== null ? number_format($stats['position'], 1) : '—' }}" />
        </div>

        {{-- 榜单一：临门一脚（排名 4~15、有展现，按点击升序——最该补强的词排最前） --}}
        <x-filament::section class="mt-6">
            <x-slot name="heading">临门一脚</x-slot>
            <x-slot name="description">平均排名 {{ config('gsc-pulse.near_miss.min_position') }}~{{ config('gsc-pulse.near_miss.max_position') }}、展现 ≥ {{ config('gsc-pulse.near_miss.min_impressions') }}；点击越少越靠前，优化内链/内容冲首页。</x-slot>
            @if ($nearMiss->isEmpty())
                <x-plugin-empty icon="heroicon-o-magnifying-glass" title="暂无符合条件的关键词"
                    description="需要平均排名进入 4~15 且展现达标的搜索词；数据积累后自动出现。" />
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500 border-b">
                                <th class="py-2">搜索词</th>
                                <th>落地页</th>
                                <th class="text-right">平均排名</th>
                                <th class="text-right">展现</th>
                                <th class="text-right">点击</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($nearMiss as $row)
                                <tr class="border-b">
                                    <td class="py-2 font-medium">{{ $row->query }}</td>
                                    <td class="max-w-xs truncate font-mono text-gray-500" title="{{ $row->page }}">{{ $row->page }}</td>
                                    <td class="text-right font-mono">{{ number_format($row->avg_position, 1) }}</td>
                                    <td class="text-right font-mono">{{ number_format($row->impressions) }}</td>
                                    <td class="text-right font-mono font-bold text-danger-500">{{ number_format($row->clicks) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        {{-- 榜单二：衰退预警（近 7 天较前 7 天点击降幅 ≥ 阈值的页面） --}}
        <x-filament::section class="mt-6">
            <x-slot name="heading">衰退预警</x-slot>
            <x-slot name="description">近 7 天点击较前 7 天降幅 ≥ {{ config('gsc-pulse.diminishing_threshold') }}% 的页面，优先排查排名下滑或内容过时。</x-slot>
            @if ($declining->isEmpty())
                <x-plugin-empty icon="heroicon-o-arrow-trending-up" title="暂无衰退页面"
                    description="没有页面点击出现大幅下滑——保持现状即可。" />
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500 border-b">
                                <th class="py-2">页面</th>
                                <th class="text-right">前 7 天点击</th>
                                <th class="text-right">近 7 天点击</th>
                                <th class="text-right">降幅</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($declining as $row)
                                <tr class="border-b">
                                    <td class="max-w-md truncate py-2 font-mono" title="{{ $row->page }}">{{ $row->page }}</td>
                                    <td class="text-right font-mono">{{ number_format($row->prev_clicks) }}</td>
                                    <td class="text-right font-mono">{{ number_format($row->recent_clicks) }}</td>
                                    <td class="text-right font-mono font-bold text-danger-500">↓{{ $row->drop_percent }}%</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        {{-- 榜单三：机会词（有展现零点击 → 标题/摘要优化标的） --}}
        <x-filament::section class="mt-6">
            <x-slot name="heading">机会词</x-slot>
            <x-slot name="description">展现 ≥ {{ config('gsc-pulse.opportunity.min_impressions') }} 且点击为 0 的搜索词：有曝光没点击，优化 TDK 或补内容承接。</x-slot>
            @if ($opportunities->isEmpty())
                <x-plugin-empty icon="heroicon-o-sparkles" title="暂无机会词"
                    description="出现「有展现但零点击」的搜索词时会列在这里。" />
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500 border-b">
                                <th class="py-2">搜索词</th>
                                <th class="text-right">展现</th>
                                <th class="text-right">点击</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($opportunities as $row)
                                <tr class="border-b">
                                    <td class="py-2 font-medium">{{ $row->query }}</td>
                                    <td class="text-right font-mono">{{ number_format($row->impressions) }}</td>
                                    <td class="text-right font-mono text-gray-400">0</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

        {{-- 分语言点击统计（按落地页路径前缀聚合） --}}
        <x-filament::section class="mt-6">
            <x-slot name="heading">分语言点击（近 {{ $days }} 天）</x-slot>
            @if (empty($byLanguage))
                <x-plugin-empty icon="heroicon-o-language" title="暂无数据"
                    description="有搜索点击后，这里按落地页语言前缀聚合展示。" />
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500 border-b">
                                <th class="py-2">语言</th>
                                <th class="text-right">点击</th>
                                <th class="text-right">占比</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($byLanguage as $row)
                                @php($share = $stats['clicks'] > 0 ? round($row['clicks'] / $stats['clicks'] * 100, 1) : 0)
                                <tr class="border-b">
                                    <td class="py-2">{{ $row['label'] }}</td>
                                    <td class="text-right font-mono">{{ number_format($row['clicks']) }}</td>
                                    <td class="text-right font-mono text-gray-500">{{ $share }}%</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    </x-filament-panels::page>
</div>
