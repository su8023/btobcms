<div>
    <x-filament-panels::page>
        @php($stats = $coverage['stats'])
        @php($locales = $coverage['locales'])
        {{-- 单元格状态 → 徽章颜色 / 符号 的映射 --}}
        @php($statusColor = ['full' => 'success', 'partial' => 'warning', 'missing' => 'danger'])
        @php($statusMark = ['full' => '✓', 'partial' => '◐', 'missing' => '✗'])
        @php($statusText = ['full' => '完整', 'partial' => '仅标题', 'missing' => '缺失'])
        @php($typeTone = ['post' => 'primary', 'product' => 'gray', 'category' => 'warning'])
        @php($typeLabel = ['post' => '文章', 'product' => '产品', 'category' => '分类'])
        @php($labelTone = ['文章' => 'primary', '产品' => 'gray', '分类' => 'warning'])

        {{-- ===== 顶部统计卡：图标 + 大数字，一眼分层 ===== --}}
        <div class="plugin-stats-grid">
            <x-plugin-stat icon="heroicon-o-document-text" tone="gray"
                label="内容总数（文章 + 产品 + 分类）" value="{{ number_format($stats['total']) }}" />
            <x-plugin-stat icon="heroicon-o-language" tone="primary"
                label="完全翻译率" value="{{ $stats['full_rate'] }}%"
                sub="{{ $stats['full_count'] }}/{{ $stats['total'] }} 全语言齐备" />
            <x-plugin-stat icon="heroicon-o-puzzle-piece"
                tone="{{ $stats['total_gaps'] > 0 ? 'warning' : 'success' }}"
                label="待补翻的语言缺口" value="{{ number_format($stats['total_gaps']) }}" />
        </div>

        {{-- ===== AI 补翻缺失语言 ===== --}}
        <x-filament::section class="mt-6">
            <x-slot name="heading">AI 补翻缺失语言</x-slot>
            <x-slot name="description">选一篇文章，AI 按语言逐个翻译（含 SEO 标题描述）并在下方实时显示进度；任务在后台每分钟消化一个，完成后矩阵自动更新。</x-slot>
            @if (! \Modules\TransCoverage\Services\TranslationFixer::available())
                <p style="text-align:left; max-width:none; font-size: var(--text-sm); color: var(--warning, #b45309)">
                    未安装 AiContentWriter 插件，AI 补翻不可用；下方矩阵仍可正常查看缺口。
                </p>
            @else
                {{-- 操作行：显式 flex + 按钮用 Filament 组件（bg-primary 等 Tailwind 类不在编译 CSS，原生按钮会灰成一片文字） --}}
                <div style="display:flex; flex-wrap:wrap; align-items:flex-end; gap:calc(var(--spacing) * 3)">
                    <div style="flex:1 1 20rem; min-width:16rem; max-width:28rem">
                        <select wire:model="fixPostId" class="plugin-input">
                            <option value="">— 选择缺语言最多的文章 —</option>
                            @foreach ($this->fixCandidates as $candidate)
                                <option value="{{ $candidate['id'] }}">{{ $candidate['label'] }}</option>
                            @endforeach
                        </select>
                        <p style="margin:4px 0 0; font-size:var(--text-xs); color:var(--gray-400)">选项括号内为该文章缺失的语言数。</p>
                    </div>
                    <x-filament::button wire:click="runFix" wire:loading.attr="disabled"
                        wire:confirm="将对选中文章缺失的所有语言排队 AI 翻译，确认继续？"
                        icon="heroicon-o-bolt">
                        开始补翻
                    </x-filament::button>
                </div>
            @endif
        </x-filament::section>

        {{-- ===== 补翻进度（5 秒轮询；全部终态后徽章保留 30 分钟供回看） ===== --}}
        @if (! empty($progress))
            <x-filament::section class="mt-6" wire:poll.5s>
                <x-slot name="heading">补翻进度</x-slot>
                @php($stateLabel = ['queued' => '排队中', 'running' => '翻译中', 'done' => '已完成', 'failed' => '失败'])
                @php($stateTone = ['queued' => 'warning', 'running' => 'info', 'done' => 'success', 'failed' => 'danger'])
                <div style="display:flex; flex-direction:column; gap:calc(var(--spacing) * 3)">
                    @foreach ($progress as $block)
                        <div class="uc-panel" style="padding:calc(var(--spacing) * 3) calc(var(--spacing) * 4)">
                            <p style="margin:0 0 8px; font-size:var(--text-sm); font-weight:600; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">
                                <span class="tp-badge tp-badge--sm tp-badge--{{ $labelTone[$block['type_label']] ?? 'gray' }}">{{ $block['type_label'] }}</span>
                                {{ $block['title'] }}
                                @if ($block['in_flight'])
                                    <span class="tp-badge tp-badge--info" style="margin-left:8px">进行中</span>
                                @endif
                            </p>
                            <div style="display:flex; flex-wrap:wrap; gap:8px">
                                @foreach ($block['entries'] as $entry)
                                    <span class="tp-badge tp-badge--{{ $stateTone[$entry['state']] ?? 'gray' }}"
                                        title="{{ $entry['at'] }}{{ $entry['note'] !== '' ? ' · '.$entry['note'] : '' }}">
                                        {{ config("site.native_names.{$entry['locale']}", strtoupper($entry['locale'])) }} · {{ $stateLabel[$entry['state']] ?? $entry['state'] }}
                                    </span>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </x-filament::section>
        @endif

        {{-- ===== 覆盖率矩阵 ===== --}}
        <x-filament::section class="mt-6">
            <x-slot name="heading">覆盖矩阵</x-slot>
            <x-slot name="description">
                {{-- 图例横排 + 自绘彩色徽章（fi-badge-color-* 不在预编译 CSS，原生类不会上色） --}}
                <span style="display:inline-flex; flex-wrap:wrap; align-items:center; gap:8px">
                    <span class="tp-badge tp-badge--success">✓ 完整</span>
                    <span class="tp-badge tp-badge--warning">◐ 仅标题</span>
                    <span class="tp-badge tp-badge--danger">✗ 缺失</span>
                    <span>按缺失语言数降序排列。</span>
                </span>
            </x-slot>
            @if (empty($coverage['rows']))
                <x-plugin-empty icon="heroicon-o-table-cells" title="暂无内容"
                    description="创建文章、产品或分类后，翻译覆盖情况会自动出现在这里。" />
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-gray-500 border-b">
                                <th class="py-2 pr-4">内容</th>
                                @foreach ($locales as $locale)
                                    <th class="px-1 text-center">{{ strtoupper($locale) }}</th>
                                @endforeach
                                <th class="pl-4 text-center" title="缺失 SEO 元数据的语言数">缺 SEO</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($coverage['rows'] as $row)
                                <tr class="border-b">
                                    <td class="py-2 pr-4">
                                        <span class="tp-badge tp-badge--sm tp-badge--{{ $typeTone[$row['type']] ?? 'gray' }}" style="margin-right:6px">
                                            {{ $typeLabel[$row['type']] ?? '内容' }}
                                        </span>
                                        {{ $row['title'] }}
                                    </td>
                                    @foreach ($locales as $locale)
                                        @php($status = $row['statuses'][$locale] ?? 'missing')
                                        <td class="px-1 text-center"
                                            title="{{ $locale }}：{{ $statusText[$status] }}">
                                            <span class="tp-badge tp-badge--sm tp-badge--{{ $statusColor[$status] }}">
                                                {{ $statusMark[$status] }}
                                            </span>
                                        </td>
                                    @endforeach
                                    <td class="pl-4 text-center font-mono"
                                        @if ($row['missing_seo'] !== null) title="缺失 SEO 元数据的语言数" @endif>
                                        {{ $row['missing_seo'] ?? '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    </x-filament-panels::page>
</div>
