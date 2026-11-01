<div>
    <x-filament-panels::page>
        @php($stats = $coverage['stats'])
        @php($locales = $coverage['locales'])
        {{-- 单元格状态 → 徽章颜色 / 符号 的映射 --}}
        @php($statusColor = ['full' => 'success', 'partial' => 'warning', 'missing' => 'danger'])
        @php($statusMark = ['full' => '✓', 'partial' => '◐', 'missing' => '✗'])
        @php($statusText = ['full' => '完整', 'partial' => '仅标题', 'missing' => '缺失'])

        {{-- ===== 顶部统计卡：图标 + 大数字，一眼分层 ===== --}}
        <div class="plugin-stats-grid">
            <x-plugin-stat icon="heroicon-o-document-text" tone="gray"
                label="内容总数（文章 + 产品）" value="{{ number_format($stats['total']) }}" />
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
            <x-slot name="description">选一篇文章，AI 自动把它缺的语言全部翻译好（含 SEO 标题描述），几分钟完成。</x-slot>
            @if (! \Modules\TransCoverage\Services\TranslationFixer::available())
                <p class="plugin-empty__desc" style="text-align:left; max-width:none; color: var(--warning-600, #b45309)">
                    未安装 AiContentWriter 插件，AI 补翻不可用；下方矩阵仍可正常查看缺口。
                </p>
            @else
                <div class="plugin-row" style="align-items:center">
                    <select wire:model="fixPostId"
                        class="plugin-input" style="min-width: 18rem">
                        <option value="">— 选择缺语言最多的文章 —</option>
                        @foreach ($this->fixCandidates as $candidate)
                            <option value="{{ $candidate['id'] }}">{{ $candidate['label'] }}</option>
                        @endforeach
                    </select>
                    <button type="button" wire:click="runFix" wire:loading.attr="disabled"
                        wire:confirm="将对选中文章缺失的所有语言执行 AI 翻译并落库，确认继续？"
                        class="rounded-lg bg-primary-500 px-4 py-2 text-sm font-medium text-white hover:bg-primary-600 disabled:opacity-50">
                        开始补翻
                    </button>
                    <span class="text-xs text-gray-400">选项括号内为该文章缺失的语言数。</span>
                </div>
            @endif
        </x-filament::section>

        {{-- ===== 覆盖率矩阵 ===== --}}
        <x-filament::section class="mt-6">
            <x-slot name="heading">覆盖矩阵</x-slot>
            <x-slot name="description">
                <span class="fi-badge fi-badge-color-success">✓ 完整</span>
                <span class="fi-badge fi-badge-color-warning">◐ 仅标题</span>
                <span class="fi-badge fi-badge-color-danger">✗ 缺失</span>
                <span class="ml-2">按缺失语言数降序排列。</span>
            </x-slot>
            @if (empty($coverage['rows']))
                <x-plugin-empty icon="heroicon-o-table-cells" title="暂无内容"
                    description="创建文章或产品后，翻译覆盖情况会自动出现在这里。" />
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
                                        <span class="fi-badge fi-badge-color-{{ $row['type'] === 'post' ? 'primary' : 'gray' }} mr-1">
                                            {{ $row['type'] === 'post' ? '文章' : '产品' }}
                                        </span>
                                        {{ $row['title'] }}
                                    </td>
                                    @foreach ($locales as $locale)
                                        @php($status = $row['statuses'][$locale] ?? 'missing')
                                        <td class="px-1 text-center"
                                            title="{{ $locale }}：{{ $statusText[$status] }}">
                                            <span class="fi-badge fi-badge-color-{{ $statusColor[$status] }}">
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
