<x-filament-panels::page>
    {{-- 中断横幅：断点继续 / 放弃（全宽告警，不参与左右分栏） --}}
    @if($this->resumable())
        <x-filament::section class="border-warning-500 mb-4">
            <x-slot:heading>⚠ 上次更新中断于「{{ $this->interruptedStep() }}」步骤（目标 {{ $this->interruptedTag() }}）</x-slot:heading>
            <x-slot:description>站点当前处于维护模式（访客看到 503）。点顶部「继续更新」从断点恢复；若想暂时恢复访客访问，点「恢复访问并放弃」。</x-slot:description>
        </x-filament::section>
    @endif

    {{-- ===== 状态行：三张信息卡 ===== --}}
    <div class="plugin-stats-grid">
        <div class="plugin-stat">
            <div class="plugin-stat__icon">
                <x-filament::icon icon="heroicon-o-cube" />
            </div>
            <div class="plugin-stat__body">
                <div class="plugin-stat__label">{{ __('admin.update_center.current_version') }}</div>
                <div class="plugin-stat__value">{{ $currentVersion }}</div>
                <div class="plugin-stat__sub">{{ __('admin.update_center.git_na') }}</div>
            </div>
        </div>

        <div class="plugin-stat {{ $this->hasUpdate() ? 'plugin-stat--primary' : 'plugin-stat--success' }}">
            <div class="plugin-stat__icon">
                <x-filament::icon :icon="$this->hasUpdate() ? 'heroicon-o-arrow-up-circle' : 'heroicon-o-check-circle'" />
            </div>
            <div class="plugin-stat__body">
                <div class="plugin-stat__label">{{ __('admin.update_center.latest_version') }}</div>
                @if($githubError !== '')
                    <div class="plugin-stat__value" style="font-size: var(--text-sm); font-weight: var(--font-weight-medium); color: var(--danger);">{{ $githubError }}</div>
                @elseif($latestTag !== null)
                    <div class="plugin-stat__value">{{ $latestTag }}</div>
                    <div class="plugin-stat__sub">
                        {{ $this->hasUpdate() ? __('admin.update_center.update_available') : __('admin.update_center.up_to_date') }}
                    </div>
                @else
                    <div class="plugin-stat__value" style="font-size: var(--text-sm); font-weight: var(--font-weight-medium); color: var(--gray-500);">{{ __('admin.update_center.no_releases') }}</div>
                @endif
            </div>
        </div>

        <div class="plugin-stat">
            <div class="plugin-stat__icon">
                <x-filament::icon icon="heroicon-o-code-bracket" />
            </div>
            <div class="plugin-stat__body">
                <div class="plugin-stat__label">{{ __('admin.update_center.repo') }}</div>
                <div class="plugin-stat__value" style="font-size: var(--text-base); font-weight: var(--font-weight-semibold); font-family: ui-monospace, SFMono-Regular, Menlo, monospace;">{{ $repo ?: '—' }}</div>
                <div class="plugin-stat__sub">
                    <a href="https://github.com/{{ $repo }}/releases" target="_blank" rel="noopener"
                        style="color: var(--primary); text-decoration: underline;">{{ __('admin.update_center.view_on_github') }}</a>
                </div>
            </div>
        </div>
    </div>

    {{-- 升级前置问题（缺 zip 扩展/目录不可写）：全宽告警 --}}
    @if($preflightProblems !== [])
        <x-filament::section class="border-danger-500 mt-6">
            <x-slot:heading>{{ __('admin.update_center.preflight_problems') }}</x-slot:heading>
            <ul class="list-disc pl-5 space-y-1">
                @foreach($preflightProblems as $problem)
                    <li class="text-sm">{{ $problem }}</li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif

    {{-- ===== 主体：左（变更说明 + 历史版本）｜右（执行日志） ===== --}}
    <div class="uc-two-col mt-6">
        {{-- 左栏：最新版变更说明 + 历史版本 --}}
        <div class="uc-stack">
            @if($latestNotes !== '' && $latestNotes !== null)
                <div class="uc-panel">
                    <p class="uc-panel__title">
                        <span>{{ __('admin.update_center.changelog') }}（{{ $latestTag }}）</span>
                    </p>
                    {{-- 内联换行样式：Filament 预编译 CSS 不含 whitespace-pre-wrap 等工具类，
                         长行（诊断日志里的 JSON 摘录）会横向溢出容器 --}}
                    <pre style="white-space: pre-wrap; overflow-wrap: anywhere; word-break: break-all; max-height: 16rem; overflow-y: auto; margin: 0; font-size: var(--text-xs); line-height: 1.7; color: var(--gray-600);"
                        class="dark:text-gray-300">{{ $latestNotes }}</pre>
                </div>
            @endif

            @if($releases !== [])
                <div class="uc-panel">
                    <p class="uc-panel__title">
                        <span>{{ __('admin.update_center.history') }}</span>
                        <span class="uc-panel__note">{{ count($releases) }} 个版本</span>
                    </p>
                    <div class="uc-releases">
                        @foreach($releases as $release)
                            <div class="uc-release {{ $release['tag'] === $currentVersion ? 'uc-release--current' : '' }}">
                                <div class="uc-release__main">
                                    <p class="uc-release__tag">
                                        {{ $release['tag'] }}
                                        @if($release['prerelease'])
                                            <span class="uc-badge-pre">测试版</span>
                                        @endif
                                        @if($release['tag'] === $currentVersion)
                                            <span class="uc-badge-current">{{ __('admin.update_center.current_badge') }}</span>
                                        @endif
                                    </p>
                                    <p class="uc-release__name">{{ $release['name'] }} · {{ $release['date'] }}</p>
                                </div>
                                @if($release['tag'] !== $currentVersion)
                                    <x-filament::button
                                        size="xs"
                                        color="gray"
                                        wire:click="updateTo('{{ $release['tag'] }}')"
                                        wire:confirm="{{ __('admin.update_center.confirm_desc') }}"
                                    >
                                        {{ __('admin.update_center.update_to') }}
                                    </x-filament::button>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        {{-- 右栏：执行日志（队列进度实时轮询） --}}
        <div class="uc-stack">
            <div class="uc-panel" wire:poll.5s="logTail">
                <p class="uc-panel__title">
                    <span>{{ __('admin.update_center.log') }}</span>
                    <span class="uc-panel__note">{{ __('admin.update_center.log_auto_refresh') }}</span>
                </p>
                <pre style="white-space: pre-wrap; overflow-wrap: anywhere; word-break: break-all; max-height: 46rem; overflow-y: auto; margin: 0; font-size: var(--text-xs); line-height: 1.7; border-radius: var(--radius-lg); padding: calc(var(--spacing) * 3); background: var(--gray-950); color: var(--gray-100);">{{ $this->logTail() ?: __('admin.update_center.log_empty') }}</pre>
            </div>
        </div>
    </div>
</x-filament-panels::page>
