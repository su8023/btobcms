<x-filament-widgets::widget>
    @php($sync = \App\Services\AppUrlHealth::inspect())
    <div class="fi-widget p-4 rounded-xl bg-white shadow dark:bg-gray-900 border-s-4 border-warning-500">
        @if ($flash)
            <div class="mb-3 p-2.5 rounded-lg text-sm
                {{ ($flash['status'] ?? '') === 'error' ? 'bg-danger-50 text-danger-700 dark:bg-danger-950' : 'bg-success-50 text-success-700 dark:bg-success-950' }}">
                {{ $flash['message'] }}
            </div>
        @endif

        <h3 class="text-base font-bold mb-1">⚠ 站点地址（APP_URL）与实际访问不一致</h3>
        <p class="text-sm text-gray-600 dark:text-gray-400 mb-1">
            当前 APP_URL：<code>{{ $sync['app_url'] }}</code>
            @if ($sync['current'])
                · 实际访问：<code>{{ $sync['current'] }}</code>
            @endif
        </p>
        <p class="text-sm text-gray-600 dark:text-gray-400 mb-3">
            @if ($sync['type'] === 'scheme')
                协议不一致：开启 HTTPS 后全站链接仍按 http:// 生成，浏览器会把样式与脚本当混合内容拦截（整站样式全丢的头号原因）。
            @else
                域名不一致：全站链接会指向另一个域名变体（如 www 与非 www、临时域名残留），造成 SEO 收录混乱与资源加载失败。
            @endif
        </p>
        {{-- action 用相对路径：绝对地址会跟随 APP_URL 协议，在坏的 https 现场
             会提交到 http:// 被浏览器当混合内容拦截——相对路径始终提交当前 origin --}}
        <form method="POST" action="{{ route('admin.app-url.sync', [], false) }}">
            @csrf
            <input type="hidden" name="app_url" value="{{ $sync['suggested'] }}">
            <button type="submit"
                    class="text-xs font-bold px-3 py-1.5 rounded-lg bg-primary-500 text-white hover:bg-primary-600">
                一键同步为 {{ $sync['suggested'] }}（自动刷新配置缓存，无需 SSH）
            </button>
        </form>
    </div>
</x-filament-widgets::widget>
