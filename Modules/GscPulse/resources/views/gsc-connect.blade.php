<div>
    <x-filament-panels::page>
        @php($error = session('gsc_error'))
        @php($connectedFlash = session('gsc_connected'))

        {{-- 回调跳回的提示（错误 / 成功） --}}
        @if ($error)
            <div class="mb-4 rounded-lg border border-danger-200 bg-danger-50 p-4 text-sm text-danger-700 dark:border-danger-800 dark:bg-danger-900/30 dark:text-danger-300">
                {{ $error }}
            </div>
        @endif
        @if ($connectedFlash)
            <div class="mb-4 rounded-lg border border-success-200 bg-success-50 p-4 text-sm text-success-700 dark:border-success-800 dark:bg-success-900/30 dark:text-success-300">
                连接成功！凭据已保存，可到「GSC 看板」点击「立即拉取」回流近 28 天数据。
            </div>
        @endif

        <x-filament::section>
            <x-slot name="heading">连接状态</x-slot>
            <div class="flex flex-wrap items-center justify-between gap-3">
                @if ($this->isConnected)
                    <div>
                        <p class="text-sm">
                            <span class="fi-badge fi-badge-color-success">已连接</span>
                            <span class="ml-2 font-mono">{{ $this->credential?->site_url }}</span>
                        </p>
                        @if ($this->credential?->token_expires_at)
                            <p class="mt-1 text-xs text-gray-500">
                                access_token 过期时间：{{ $this->credential->token_expires_at->format('Y-m-d H:i') }}（过期前会自动刷新）
                            </p>
                        @endif
                    </div>
                    <button type="button" wire:click="disconnect"
                        wire:confirm="确定断开 Google Search Console 连接吗？断开后每日拉取将静默跳过。"
                        class="rounded-lg bg-danger-500 px-4 py-2 text-sm font-medium text-white hover:bg-danger-600">
                        断开
                    </button>
                @else
                    <p class="text-sm text-gray-500">
                        <span class="fi-badge fi-badge-color-gray">未连接</span>
                        <span class="ml-2">尚未授权 Google Search Console 数据读取。</span>
                    </p>
                @endif
            </div>
        </x-filament::section>

        <x-filament::section class="mt-6">
            <x-slot name="heading">Google OAuth 应用凭据</x-slot>
            <x-slot name="description">在 Google Cloud Console 创建 OAuth 客户端（Web 应用），并把回调 URI 填为：{{ route('gsc-pulse.callback') }}</x-slot>
            <div class="max-w-xl space-y-4">
                <div>
                    <label class="mb-1 block text-sm font-medium">Client ID</label>
                    <input type="text" wire:model="clientId"
                        class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900"
                        placeholder="xxxx.apps.googleusercontent.com">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Client Secret</label>
                    <input type="text" wire:model="clientSecret"
                        class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900"
                        placeholder="GOCSPX-...">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">GSC 站点属性（site_url）</label>
                    <input type="text" wire:model="siteUrl"
                        class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900"
                        placeholder="https://www.aples.xyz/">
                    <p class="mt-1 text-xs text-gray-500">须与 Search Console 中的资源完全一致（建议带尾斜杠）；改动后重新授权。</p>
                </div>
                <button type="button" wire:click="save"
                    class="rounded-lg bg-primary-500 px-4 py-2 text-sm font-medium text-white hover:bg-primary-600">
                    保存凭据
                </button>
            </div>
        </x-filament::section>

        <x-filament::section class="mt-6">
            <x-slot name="heading">连接 Google</x-slot>
            <div class="flex flex-wrap items-center gap-3">
                @if ($this->authReady)
                    <a href="{{ $this->authUrl }}"
                        class="rounded-lg bg-primary-500 px-4 py-2 text-sm font-medium text-white hover:bg-primary-600">
                        连接 Google
                    </a>
                    <p class="text-xs text-gray-500">
                        仅申请 <span class="font-mono">webmasters.readonly</span> 只读权限；将跳转 Google 登录并授权后返回本页。
                    </p>
                @else
                    <p class="text-sm text-gray-500">请先在上方填写并保存 Client ID 与 Client Secret，再连接 Google。</p>
                @endif
            </div>
        </x-filament::section>
    </x-filament-panels::page>
</div>
