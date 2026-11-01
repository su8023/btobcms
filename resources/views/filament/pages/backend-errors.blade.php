{{--
    后台错误诊断页

    样式约定：Filament 面板的 CSS 是预编译产物，只含 Filament 自己用到的类；
    自定义类（uc-* / plugin-*）必须来自 public/css/admin-plugins.css，
    且类名要与该文件里的写法完全一致，否则整块样式静默失效。
    （此前踩过的坑：自定义 Tailwind 类不在 Filament 预编译 CSS 里。）

    渲染约定：异常消息可能很长、含换行或 HTML —— 一律用 {{ }} 转义输出，
    绝不用 {!! !!}；超长文本用 Str::limit 截断并保留完整值在 title 属性里。
--}}
<div>
    <x-filament-panels::page>
        <x-filament::section>
            <x-slot name="heading">最近的后台请求失败</x-slot>
            <x-slot name="description">
                后台页面弹出「页面加载出错」时，真实异常会记录在这里（同时写入 storage/logs/laravel.log）。
                排查步骤：找到对应时间与 URL 的记录 → 把「异常」与「位置」发给技术人员。
            </x-slot>
            {{-- Filament v5 的 section 组件只有 afterHeader 插槽，没有 headerEnd；
                 用错插槽名的后果是「清空记录」按钮整块不渲染（不报错，只是消失）。 --}}
            <x-slot name="afterHeader">
                <x-filament::button size="xs" color="gray" wire:click="clear"
                    wire:confirm="确定清空诊断记录吗？（只清除本页的失败记录，不动其他日志）">
                    清空记录
                </x-filament::button>
            </x-slot>

            @php($entries = $this->entries)

            @if ($entries === [])
                <x-plugin-empty icon="heroicon-o-shield-check" title="没有失败记录"
                    description="后台请求一切正常。若页面仍弹「页面加载出错」，多半是前端 Livewire 请求失败（而非后端异常），请检查浏览器控制台或稍后重试。" />
            @else
                <div class="uc-releases" style="max-height: 40rem;">
                    @foreach ($entries as $entry)
                        <div class="uc-release" style="align-items: flex-start;">
                            <div class="uc-release__main">
                                <p class="uc-release__tag">
                                    <span class="uc-badge-pre" style="background: color-mix(in oklab, var(--danger) 15%, transparent); color: var(--danger);">
                                        {{ $entry['status'] ?? 'ERR' }}
                                    </span>
                                    {{-- Filament 预编译 CSS 里没有 .font-mono 工具类（只有 CSS 变量 --font-mono），
                                         直接用变量做等宽字体，避免「看起来没生效」。 --}}
                                    <span style="font-family: var(--font-mono);">
                                        {{ $entry['method'] }} {{ $entry['url'] }}
                                    </span>
                                </p>
                                @if ($entry['exception'] !== '' || $entry['message'] !== '')
                                    <p class="uc-release__name" title="{{ $entry['message'] }}"
                                        style="white-space: pre-wrap; overflow-wrap: anywhere; color: var(--danger);">
                                        {{ $entry['exception'] }}{{ $entry['exception'] !== '' && $entry['message'] !== '' ? '：' : '' }}{{ \Illuminate\Support\Str::limit($entry['message'], 2000) }}
                                    </p>
                                @endif
                                @if ($entry['at'] !== '')
                                    <p class="uc-release__name" title="{{ $entry['at'] }}"
                                        style="overflow-wrap: anywhere;">位置：{{ \Illuminate\Support\Str::limit($entry['at'], 500) }}</p>
                                @endif
                                <p class="uc-release__name">{{ $entry['time'] }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::section>
    </x-filament-panels::page>
</div>
