<x-filament-panels::page>
    @php
        $grouped = $this->getPlugins();

        // 分类别注：图标 + 渐变色（插件中心视觉系统）
        $meta = [
            'notification' => ['icon' => 'M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9M13.7 21a2 2 0 0 1-3.4 0', 'from' => '#3b82f6', 'to' => '#1d4ed8'],
            'ai'           => ['icon' => 'M12 3l1.9 5.7a2 2 0 0 0 1.3 1.3L21 12l-5.8 1.9a2 2 0 0 0-1.3 1.3L12 21l-1.9-5.8a2 2 0 0 0-1.3-1.3L3 12l5.8-2a2 2 0 0 0 1.3-1.3L12 3Z', 'from' => '#8b5cf6', 'to' => '#6d28d9'],
            'service'      => ['icon' => 'M21 12c0 4.4-4 8-9 8a9.9 9.9 0 0 1-4-.8L3 20l1.3-3.9A7.4 7.4 0 0 1 3 12c0-4.4 4-8 9-8s9 3.6 9 8Z', 'from' => '#10b981', 'to' => '#047857'],
            'marketing'    => ['icon' => 'M3 11l18-5v12L3 13v-2ZM11.6 16.8a3 3 0 1 1-5.8-1.6', 'from' => '#f97316', 'to' => '#c2410c'],
            'seo'          => ['icon' => 'M21 21l-4.35-4.35M17 10.5a6.5 6.5 0 1 1-13 0 6.5 6.5 0 0 1 13 0Z', 'from' => '#14b8a6', 'to' => '#0f766e'],
            'integration'  => ['icon' => 'M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7.1-7.1l-1.7 1.7M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7.1 7.1l1.7-1.7', 'from' => '#64748b', 'to' => '#334155'],
            'general'      => ['icon' => 'M12 3l1.9 5.7a2 2 0 0 0 1.3 1.3L21 12l-5.8 1.9a2 2 0 0 0-1.3 1.3L12 21l-1.9-5.8a2 2 0 0 0-1.3-1.3L3 12l5.8-2a2 2 0 0 0 1.3-1.3L12 3Z', 'from' => '#94a3b8', 'to' => '#64748b'],
        ];
    @endphp

    <style>
        .bpcb { display: flex; flex-direction: column; gap: 28px; }
        .bpcb-cat { display: flex; flex-direction: column; gap: 12px; }
        .bpcb-cat-head { display: flex; align-items: center; gap: 10px; }
        .bpcb-cat-title { font-size: .95rem; font-weight: 800; letter-spacing: .01em; color: var(--gray-700, #364152); }
        .dark .bpcb-cat-title { color: var(--gray-300, #d5dceb); }
        .bpcb-cat-count {
            font-size: .72rem; font-weight: 700; color: var(--gray-500, #6c7387);
            background: var(--gray-100, #f3f4f6); border-radius: 999px; padding: 2px 9px;
        }
        .dark .bpcb-cat-count { background: rgba(255,255,255,.08); color: var(--gray-400, #9aa3b5); }
        .bpcb-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
        @media (max-width: 860px) { .bpcb-grid { grid-template-columns: 1fr; } }

        .bpcb-card {
            display: flex; align-items: flex-start; gap: 14px;
            background: var(--gray-50, #f9fafb); border: 1px solid var(--gray-200, #e2e5ee);
            border-radius: 14px; padding: 16px 18px;
            transition: box-shadow .18s ease, transform .18s ease, border-color .18s ease;
        }
        .bpcb-card:hover { box-shadow: 0 10px 26px -10px rgba(15, 23, 42, .18); transform: translateY(-2px); border-color: var(--gray-300, #cbd2e0); }
        .dark .bpcb-card { background: rgba(255,255,255,.04); border-color: rgba(255,255,255,.09); }
        .dark .bpcb-card:hover { border-color: rgba(255,255,255,.2); }

        .bpcb-tile {
            width: 46px; height: 46px; border-radius: 12px; flex-shrink: 0;
            display: grid; place-items: center; color: #fff;
            box-shadow: inset 0 1px 0 rgba(255,255,255,.28), 0 6px 14px -6px var(--tile-shadow, rgba(13,74,143,.5));
        }
        .bpcb-tile svg { width: 23px; height: 23px; }

        .bpcb-main { min-width: 0; flex: 1; }
        .bpcb-name-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .bpcb-name { font-weight: 750; font-size: .95rem; color: var(--gray-800, #1b2537); letter-spacing: -.01em; }
        .dark .bpcb-name { color: var(--gray-100, #eceef3); }
        .bpcb-ver {
            font-size: .7rem; font-weight: 700; color: var(--gray-500, #6c7387);
            background: var(--gray-100, #f3f4f6); padding: 1px 7px; border-radius: 6px; font-variant-numeric: tabular-nums;
        }
        .dark .bpcb-ver { background: rgba(255,255,255,.08); color: var(--gray-400, #9aa3b5); }

        .bpcb-status { display: inline-flex; align-items: center; gap: 5px; font-size: .74rem; font-weight: 700; }
        .bpcb-dot { width: 7px; height: 7px; border-radius: 999px; }
        .bpcb-status--on { color: #15803d; } .bpcb-status--on .bpcb-dot { background: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,.18); }
        .bpcb-status--off { color: #b91c1c; } .bpcb-status--off .bpcb-dot { background: #ef4444; box-shadow: 0 0 0 3px rgba(239,68,68,.16); }
        .dark .bpcb-status--on { color: #4ade80; } .dark .bpcb-status--off { color: #f87171; }

        .bpcb-desc { margin: 5px 0 0; font-size: .83rem; line-height: 1.55; color: var(--gray-600, #556077); }
        .dark .bpcb-desc { color: var(--gray-400, #9aa3b5); }

        /* 设置面板 */
        .bpcb-settings-link {
            display: inline-flex; align-items: center; gap: 6px;
            margin-top: 10px; padding: 5px 10px; border-radius: 8px;
            font-size: .78rem; font-weight: 650; color: var(--gray-600, #556077);
            transition: all .14s ease;
        }
        .bpcb-settings-link svg { width: 14px; height: 14px; }
        .bpcb-settings-link:hover { color: var(--gray-800, #1b2537); background: var(--gray-100, #f3f4f6); }
        .dark .bpcb-settings-link { color: var(--gray-400, #9aa3b5); }
        .dark .bpcb-settings-link:hover { color: var(--gray-200, #eceef3); background: rgba(255,255,255,.06); }
        .bpcb-settings-link span[aria-hidden] { margin-inline-start: auto; }

        /* 开关 */
        .bpcb-switch {
            position: relative; flex-shrink: 0; margin-top: 3px;
            width: 46px; height: 26px; border-radius: 999px; border: none; cursor: pointer;
            background: var(--gray-300, #c6cddc); transition: background .18s ease;
        }
        .bpcb-switch::after {
            content: ""; position: absolute; top: 3px; inset-inline-start: 3px;
            width: 20px; height: 20px; border-radius: 999px; background: #fff;
            box-shadow: 0 1px 3px rgba(15, 23, 42, .35); transition: transform .18s ease;
        }
        .bpcb-switch--on { background: linear-gradient(135deg, #22c55e, #16a34a); }
        .bpcb-switch--on::after { transform: translateX(20px); }
        [dir="rtl"] .bpcb-switch--on::after { transform: translateX(-20px); }
        .bpcb-switch:focus-visible { outline: 3px solid var(--primary-100, #d5e5f7); outline-offset: 2px; }

        .bpcb-empty {
            border: 1px dashed var(--gray-300, #cbd2e0); border-radius: 14px;
            padding: 40px; text-align: center; color: var(--gray-500, #6c7387);
        }
        .bpcb-footer { font-size: .78rem; color: var(--gray-500, #6c7387); display: flex; gap: 6px; align-items: baseline; }
        .bpcb-footer code {
            background: var(--gray-100, #f3f4f6); padding: 1px 6px; border-radius: 5px;
            font-size: .72rem; color: var(--gray-600, #556077);
        }
        .dark .bpcb-footer code { background: rgba(255,255,255,.08); color: var(--gray-400, #9aa3b5); }
    </style>

    <div class="bpcb">
        @forelse($grouped as $category => $plugins)
            <section class="bpcb-cat">
                <div class="bpcb-cat-head">
                    <span class="bpcb-cat-title">{{ __('admin.plugins.categories.'.$category) }}</span>
                    <span class="bpcb-cat-count">{{ count($plugins) }}</span>
                </div>

                <div class="bpcb-grid">
                    @foreach($plugins as $plugin)
                        @php($m = $meta[$category] ?? $meta['general'])
                        <article class="bpcb-card">
                            <span class="bpcb-tile" style="background: linear-gradient(135deg, {{ $m['from'] }}, {{ $m['to'] }}); --tile-shadow: {{ $m['from'] }}88;">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <path d="{{ $m['icon'] }}"/>
                                </svg>
                            </span>

                            <div class="bpcb-main">
                                <div class="bpcb-name-row">
                                    <span class="bpcb-name">{{ $plugin['name'] }}</span>
                                    <span class="bpcb-ver">v{{ $plugin['version'] }}</span>
                                    @if($plugin['enabled'])
                                        <span class="bpcb-status bpcb-status--on"><span class="bpcb-dot"></span>{{ __('admin.plugins.state_enabled') }}</span>
                                    @else
                                        <span class="bpcb-status bpcb-status--off"><span class="bpcb-dot"></span>{{ __('admin.plugins.state_disabled') }}</span>
                                    @endif
                                </div>
                                @if($plugin['description'] !== '')
                                    <p class="bpcb-desc">{{ $plugin['description'] }}</p>
                                @endif

                                @if($this->hasSettings($plugin['name']))
                                    <a class="bpcb-settings-link"
                                       href="{{ $this->settingsUrl($plugin['name']) }}">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12.2 3h-.4a2 2 0 0 0-2 2v.3a2 2 0 0 1-1 1.7l-.5.3a2 2 0 0 1-2 0l-.2-.2a2 2 0 0 0-2.8 0l-.3.3a2 2 0 0 0 0 2.8l.2.2a2 2 0 0 1 0 2l-.3.5a2 2 0 0 1-1.7 1H3a2 2 0 0 0-2 2v.4a2 2 0 0 0 2 2h.3a2 2 0 0 1 1.7 1l.3.5a2 2 0 0 1 0 2l-.2.2a2 2 0 0 0 0 2.8l.3.3a2 2 0 0 0 2.8 0l.2-.2a2 2 0 0 1 2 0l.5.3a2 2 0 0 1 1 1.7v.3a2 2 0 0 0 2 2h.4a2 2 0 0 0 2-2v-.3a2 2 0 0 1 1-1.7l.5-.3a2 2 0 0 1 2 0l.2.2a2 2 0 0 0 2.8 0l.3-.3a2 2 0 0 0 0-2.8l-.2-.2a2 2 0 0 1 0-2l.3-.5a2 2 0 0 1 1.7-1h.3a2 2 0 0 0 2-2v-.4a2 2 0 0 0-2-2h-.3a2 2 0 0 1-1.7-1l-.3-.5a2 2 0 0 1 0-2l.2-.2a2 2 0 0 0 0-2.8l-.3-.3a2 2 0 0 0-2.8 0l-.2.2a2 2 0 0 1-2 0l-.5-.3a2 2 0 0 1-1-1.7V5a2 2 0 0 0-2-2Z"/><circle cx="12" cy="12" r="3"/></svg>
                                        {{ __('admin.plugins.settings') }}
                                        <span aria-hidden="true">→</span>
                                    </a>
                                @endif
                            </div>

                            <button type="button"
                                    class="bpcb-switch {{ $plugin['enabled'] ? 'bpcb-switch--on' : '' }}"
                                    wire:click="toggle('{{ $plugin['name'] }}')"
                                    @if($plugin['enabled']) wire:confirm="{{ __('admin.plugins.disable_confirm', ['name' => $plugin['name']]) }}" @endif
                                    role="switch" aria-checked="{{ $plugin['enabled'] ? 'true' : 'false' }}"
                                    aria-label="{{ $plugin['name'] }}: {{ $plugin['enabled'] ? __('admin.plugins.state_enabled') : __('admin.plugins.state_disabled') }}"
                            ></button>
                        </article>
                    @endforeach
                </div>
            </section>
        @empty
            <div class="bpcb-empty">{{ __('admin.plugins.empty') }}</div>
        @endforelse

        <p class="bpcb-footer">
            <span>💡</span>
            <span>{!! __('admin.plugins.footer') !!}</span>
        </p>
    </div>
</x-filament-panels::page>
