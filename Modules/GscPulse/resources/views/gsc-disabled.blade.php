{{--
    插件自身开关关闭时的统一空态。

    WHY 需要独立视图：后台页面由核心按「模块启用」发现（App\Support\PluginPages），
    与本插件的 enabled 开关无关——开关关掉后页面仍在导航里。原页面模板要 route('gsc-pulse.callback')
    生成回调地址，路由一旦没注册就会抛 RouteNotFoundException → 后台 500。
    这里不碰任何路由与凭据，直接给出「未启用」的明确说明。
--}}
<div>
    <x-filament-panels::page>
        <x-filament::section>
            <x-slot name="heading">功能未启用</x-slot>

            <p class="text-sm text-gray-500">
                {{ $this->getTitle() }} 当前不可用：Google Search Console 数据回流插件（GscPulse）处于关闭状态，
                本页不提供授权入口，也不会拉取或展示任何 GSC 数据。
            </p>

            <p class="mt-2 text-sm text-gray-500">
                如需启用，请在插件中心开启 GscPulse 插件，或将环境变量
                <span class="font-mono">GSC_PULSE_ENABLED</span> 设为
                <span class="font-mono">true</span>，然后刷新本页面。
            </p>
        </x-filament::section>
    </x-filament-panels::page>
</div>
