<x-filament-panels::page>
    {{-- 当前版本 vs 最新版本 --}}
    <div class="mb-4 grid gap-4 md:grid-cols-3">
        <x-filament::section>
            <x-slot:heading>{{ __('admin.update_center.current_version') }}</x-slot:heading>
            <p class="text-2xl font-bold">{{ $currentVersion }}</p>
            @if($gitTag || $gitCommit)
                <p class="text-xs text-gray-500 mt-1">git: {{ $gitTag ?? $gitCommit }}</p>
            @else
                <p class="text-xs text-gray-500 mt-1">{{ __('admin.update_center.git_na') }}</p>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot:heading>{{ __('admin.update_center.latest_version') }}</x-slot:heading>
            @if($githubError !== '')
                <p class="text-sm text-danger-600">{{ $githubError }}</p>
            @elseif($latestTag !== null)
                <p class="text-2xl font-bold {{ $hasUpdate() ? 'text-success-600' : '' }}">{{ $latestTag }}</p>
                <p class="text-xs text-gray-500 mt-1">
                    {{ $hasUpdate() ? __('admin.update_center.update_available') : __('admin.update_center.up_to_date') }}
                </p>
            @else
                <p class="text-sm text-gray-500">{{ __('admin.update_center.no_releases') }}</p>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot:heading>{{ __('admin.update_center.repo') }}</x-slot:heading>
            <p class="text-sm font-mono">{{ $repo ?: '—' }}</p>
            <p class="text-xs text-gray-500 mt-1">
                <a href="https://github.com/{{ $repo }}/releases" target="_blank" rel="noopener" class="text-primary-600 underline">
                    {{ __('admin.update_center.view_on_github') }}
                </a>
            </p>
        </x-filament::section>
    </div>

    {{-- 升级前置问题（git 未装/非 git 仓库/有本地改动） --}}
    @if($preflightProblems !== [])
        <x-filament::section class="border-danger-500">
            <x-slot:heading>{{ __('admin.update_center.preflight_problems') }}</x-slot:heading>
            <ul class="list-disc pl-5 space-y-1">
                @foreach($preflightProblems as $problem)
                    <li class="text-sm">{{ $problem }}</li>
                @endforeach
            </ul>
        </x-filament::section>
    @endif

    {{-- 最新版变更说明 --}}
    @if($latestNotes !== '' && $latestNotes !== null)
        <x-filament::section>
            <x-slot:heading>{{ __('admin.update_center.changelog') }}（{{ $latestTag }}）</x-slot:heading>
            <pre class="whitespace-pre-wrap text-sm font-mono bg-gray-50 dark:bg-gray-900 rounded-lg p-4 max-h-96 overflow-y-auto">{{ $latestNotes }}</pre>
        </x-filament::section>
    @endif

    {{-- 历史版本 --}}
    @if($releases !== [])
        <x-filament::section>
            <x-slot:heading>{{ __('admin.update_center.history') }}</x-slot:heading>
            <div class="space-y-2">
                @foreach($releases as $release)
                    <div class="flex items-center justify-between gap-3 rounded-lg border p-3">
                        <div class="min-w-0">
                            <p class="font-semibold text-sm">
                                {{ $release['tag'] }}
                                @if($release['prerelease'])
                                    <span class="ml-1 text-xs text-warning-600">pre-release</span>
                                @endif
                                @if($release['tag'] === $currentVersion)
                                    <span class="ml-1 text-xs text-success-600">{{ __('admin.update_center.current_badge') }}</span>
                                @endif
                            </p>
                            <p class="text-xs text-gray-500 truncate">{{ $release['name'] }} · {{ $release['date'] }}</p>
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
        </x-filament::section>
    @endif

    {{-- 执行日志（队列进度实时轮询） --}}
    <x-filament::section wire:poll.5s="logTail">
        <x-slot:heading>{{ __('admin.update_center.log') }}</x-slot:heading>
        <pre class="whitespace-pre-wrap text-xs font-mono bg-gray-950 text-gray-100 rounded-lg p-4 max-h-96 overflow-y-auto">{{ $logTail() ?: __('admin.update_center.log_empty') }}</pre>
    </x-filament::section>
</x-filament-panels::page>
