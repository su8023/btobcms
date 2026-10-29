<div>
    <x-filament-panels::page>
        @php($stats = $coverage['stats'])
        @php($locales = $coverage['locales'])
        {{-- 单元格状态 → 徽章颜色 / 符号 的映射 --}}
        @php($statusColor = ['full' => 'success', 'partial' => 'warning', 'missing' => 'danger'])
        @php($statusMark = ['full' => '✓', 'partial' => '◐', 'missing' => '✗'])
        @php($statusText = ['full' => '完整', 'partial' => '仅标题', 'missing' => '缺失'])

        {{-- ===== 顶部统计卡 ===== --}}
        <div class="grid gap-4 md:grid-cols-3">
            <div class="fi-widget p-4 rounded-xl bg-white shadow dark:bg-gray-900">
                <div class="text-sm text-gray-500">内容总数（文章 + 产品）</div>
                <div class="text-3xl font-bold">{{ number_format($stats['total']) }}</div>
            </div>
            <div class="fi-widget p-4 rounded-xl bg-white shadow dark:bg-gray-900">
                <div class="text-sm text-gray-500">完全翻译率</div>
                <div class="text-3xl font-bold">
                    {{ $stats['full_rate'] }}%
                    <span class="text-base font-normal text-gray-400">{{ $stats['full_count'] }}/{{ $stats['total'] }}</span>
                </div>
            </div>
            <div class="fi-widget p-4 rounded-xl bg-white shadow dark:bg-gray-900">
                <div class="text-sm text-gray-500">总缺口数（内容 × 语言）</div>
                <div class="text-3xl font-bold {{ $stats['total_gaps'] > 0 ? 'text-danger-500' : 'text-success-500' }}">
                    {{ number_format($stats['total_gaps']) }}
                </div>
            </div>
        </div>

        {{-- ===== AI 补翻缺失语言 ===== --}}
        <x-filament::section class="mt-6">
            <x-slot name="heading">AI 补翻缺失语言</x-slot>
            <x-slot name="description">选择文章后一键补齐其全部缺失语言的标题/摘要/正文与 SEO 元数据（调用 AiContentWriter 的翻译服务，逐语言一次 AI 调用）。</x-slot>
            @if (! \Modules\TransCoverage\Services\TranslationFixer::available())
                <p class="text-sm text-warning-600">
                    未安装 AiContentWriter 插件，AI 补翻不可用；下方矩阵仍可正常查看缺口。
                </p>
            @else
                <div class="flex flex-wrap items-center gap-3">
                    <select wire:model="fixPostId"
                        class="min-w-72 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900">
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
                <p class="text-sm text-gray-500">暂无内容：创建文章或产品后自动出现在这里。</p>
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
