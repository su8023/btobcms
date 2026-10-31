<x-filament-panels::page>
    @php
        // 单行 @php(...) 在 Livewire 组件上下文会被编译成未闭合的 <?php 片段（实测 500），用块形式
        $reports = $this->getReports();
    @endphp

    @if($reports === [])
        <p class="fi-text fi-color-gray">{{ __('admin.seo_audit.empty') }}</p>
    @else
        <div class="fi-tasks grid gap-4">
            @foreach($reports as $report)
                <details class="fi-section rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900" @if($report['is_latest']) open @endif>
                    <summary class="cursor-pointer font-semibold">
                        {{ $report['finished_at'] ? \Illuminate\Support\Carbon::parse($report['finished_at'])->format('Y-m-d H:i') : $report['file'] }}
                        —
                        {{ __('admin.seo_audit.checked_count', ['count' => $report['checked']]) }} ·
                        @if($report['issue_count'] === 0)
                            <span class="text-green-600">{{ __('admin.seo_audit.no_issues') }}</span>
                        @else
                            <span class="text-orange-500">{{ __('admin.seo_audit.issue_count', ['count' => $report['issue_count']]) }}</span>
                        @endif
                    </summary>

                    @if($report['summary'] !== [])
                        <table class="mt-3 w-full text-sm">
                            <thead>
                                <tr class="text-start text-gray-500">
                                    <th class="py-1 text-start">{{ __('admin.seo_audit.issue_type') }}</th>
                                    <th class="py-1 text-start">{{ __('admin.common.count') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($report['summary'] as $type => $count)
                                    <tr class="border-t border-gray-100 dark:border-gray-800">
                                        <td class="py-1">{{ $type }}</td>
                                        <td class="py-1">{{ $count }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif

                    @if($report['issues'] !== [])
                        <table class="mt-3 w-full text-sm">
                            <thead>
                                <tr class="text-start text-gray-500">
                                    <th class="py-1 text-start">{{ __('admin.seo_audit.issue_type') }}</th>
                                    <th class="py-1 text-start">URL</th>
                                    <th class="py-1 text-start">{{ __('admin.seo_audit.detail') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($report['issues'] as $issue)
                                    <tr class="border-t border-gray-100 dark:border-gray-800">
@php
                                        // 小白可读的问题类型映射（未收录的类型原样显示）
                                        $issueTypeLabels = [
                                            'title_missing' => '页面缺标题',
                                            'title_too_long' => '标题太长（谷歌会截断）',
                                            'title_too_short' => '标题太短（浪费排名机会）',
                                            'h1_missing' => '页面缺主标题（H1）',
                                            'h1_multiple' => '主标题（H1）不止一个',
                                            'meta' => '页面描述问题',
                                            'canonical_missing' => '缺重复页面标记（canonical）',
                                            'canonical' => '重复页面标记（canonical）问题',
                                        ];
                                        $issueTypeLabel = $issueTypeLabels[$issue['type']] ?? $issue['type'];
                                    @endphp
                                        <td class="py-1 text-xs">{{ $issueTypeLabel }}</td>
                                        <td class="py-1">
                                            @php
                                                // 只有 http/https 才做成链接：
                                                // sitemap 里的 <loc> 是外部可控数据，
                                                // javascript:alert(1) 被渲染成 href 就是后台域内的 XSS。
                                                $issueUrl = (string) ($issue['url'] ?? '');
                                                $issueUrlSafe = \Modules\AiSeoAuditor\Support\SafeUrl::isHttpUrl($issueUrl);
                                            @endphp
                                            @if ($issueUrlSafe)
                                                <a class="fi-link" href="{{ $issueUrl }}" target="_blank" rel="noopener nofollow">{{ \Illuminate\Support\Str::limit($issueUrl, 60) }}</a>
                                            @else
                                                <span class="fi-link text-gray-500">{{ \Illuminate\Support\Str::limit($issueUrl, 60) }}</span>
                                            @endif
                                        </td>
                                        <td class="py-1 text-gray-500">{{ $issue['detail'] ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </details>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
