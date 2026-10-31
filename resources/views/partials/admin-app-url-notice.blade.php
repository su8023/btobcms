{{-- 管理员专属环境提醒（v1.4.19）：APP_URL 与实际访问的协议/域名不一致。
     为什么放前台：在宝塔「装完才开 HTTPS」的现场，整站 CSS/JS 被浏览器当混合内容
     拦截，后台 Livewire 全部失灵——这里的纯 HTML 表单提交是唯一还能点得动的
     修复入口。访客与搜索引擎不可见（外层 @includeWhen(user_is_admin()) 已挡）。 --}}
@php($appUrlSyncFlash = session('app_url_sync'))
@if ($appUrlSyncFlash)
    @php($syncOk = ($appUrlSyncFlash['status'] ?? '') !== 'error')
    <div class="container" style="margin-top:12px">
        <div style="border:1px solid {{ $syncOk ? '#86efac' : '#fca5a5' }};background:{{ $syncOk ? '#f0fdf4' : '#fef2f2' }};color:#1f2937;padding:10px 14px;border-radius:8px;font-size:14px">
            {{ $appUrlSyncFlash['message'] }}
        </div>
    </div>
@endif
@php($appUrlHealth = \App\Services\AppUrlHealth::inspect())
@if (! $appUrlHealth['ok'])
    <div style="background:#fffbeb;border-bottom:2px solid #f59e0b;color:#78350f;font-size:14px;line-height:1.6">
        <div class="container" style="padding-top:10px;padding-bottom:10px;display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between">
            <span>
                <strong>⚠ 站点地址待同步</strong> —
                APP_URL 是 <code>{{ $appUrlHealth['app_url'] }}</code>，当前通过 <code>{{ $appUrlHealth['current'] }}</code> 访问。
                @if ($appUrlHealth['type'] === 'scheme')
                    协议不一致会让浏览器拦截全站样式/脚本（混合内容），这就是「开了 HTTPS 样式全丢」的原因。
                @else
                    域名不一致会让全站链接指向另一域名变体，影响 SEO 收录与资源加载。
                @endif
            </span>
            {{-- action 相对路径：不跟随 APP_URL 协议，混合内容坏站点上仍可提交当前 origin --}}
            <form method="POST" action="{{ route('admin.app-url.sync', [], false) }}" style="flex-shrink:0">
                @csrf
                <input type="hidden" name="app_url" value="{{ $appUrlHealth['suggested'] }}">
                <button type="submit" style="background:#f59e0b;color:#fff;border:0;border-radius:6px;padding:7px 14px;font-weight:700;cursor:pointer">
                    一键同步为 {{ $appUrlHealth['suggested'] }}
                </button>
            </form>
        </div>
    </div>
@endif
