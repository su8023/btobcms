<x-filament-panels::page>
    @php($themes = $this->getThemes())

    <style>
        .thc { display: flex; flex-direction: column; gap: 16px; }
        .thc-card {
            display: flex; align-items: flex-start; gap: 16px;
            background: var(--gray-50, #f9fafb); border: 1px solid var(--gray-200, #e2e5ee);
            border-radius: 14px; padding: 16px 18px; transition: all .18s ease;
        }
        .thc-card:hover { box-shadow: 0 10px 26px -10px rgba(15, 23, 42, .18); border-color: var(--gray-300, #cbd2e0); }
        .dark .thc-card { background: rgba(255,255,255,.04); border-color: rgba(255,255,255,.09); }
        .thc-card--active { border-color: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,.12); }

        .thc-swatch {
            width: 54px; height: 54px; border-radius: 12px; flex-shrink: 0;
            background: linear-gradient(135deg, #0d4a8f, #062a52);
            display: grid; place-items: center; color: #fff;
        }

        .thc-name-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
        .thc-name { font-weight: 750; font-size: .95rem; color: var(--gray-800, #1b2537); }
        .dark .thc-name { color: var(--gray-100, #eceef3); }
        .thc-ver {
            font-size: .7rem; font-weight: 700; color: var(--gray-500, #6c7387);
            background: var(--gray-100, #f3f4f6); padding: 1px 7px; border-radius: 6px;
        }
        .dark .thc-ver { background: rgba(255,255,255,.08); color: var(--gray-400, #9aa3b5); }
        .thc-badge {
            display: inline-flex; align-items: center; gap: 5px; font-size: .74rem; font-weight: 700;
        }
        .thc-badge--active { color: #15803d; }
        .thc-badge--active::before { content: ""; width: 7px; height: 7px; border-radius: 999px; background: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,.18); }
        .thc-desc { margin: 5px 0 0; font-size: .83rem; line-height: 1.55; color: var(--gray-600, #556077); }
        .dark .thc-desc { color: var(--gray-400, #9aa3b5); }
        .thc-author { font-size: .72rem; color: var(--gray-500, #6c7387); margin-top: 4px; }

        .thc-actions { display: flex; flex-direction: column; gap: 8px; flex-shrink: 0; }
        .thc-btn {
            display: inline-flex; align-items: center; justify-content: center;
            padding: 7px 16px; border-radius: 9px; cursor: pointer; font: inherit;
            font-size: .78rem; font-weight: 700; white-space: nowrap;
        }
        .thc-btn--activate { background: linear-gradient(135deg, #2563eb, #1d4ed8); color: #fff; border: none; }
        .thc-btn--activate:hover { filter: brightness(1.1); }
        .thc-btn--danger {
            background: transparent; border: 1px solid #f2caca; color: #b91c1c;
        }
        .thc-btn--danger:hover { background: #fdefef; }
        .dark .thc-btn--danger { border-color: rgba(239,68,68,.35); color: #f87171; }
        .dark .thc-btn--danger:hover { background: rgba(239,68,68,.1); }
        .thc-tag { font-size: .7rem; color: var(--gray-500, #6c7387); }

        .thc-footer { font-size: .78rem; color: var(--gray-500, #6c7387); }
        .thc-footer code { background: var(--gray-100, #f3f4f6); padding: 1px 6px; border-radius: 5px; font-size: .72rem; }
        .dark .thc-footer code { background: rgba(255,255,255,.08); }
    </style>

    <div class="thc">
        @forelse($themes as $theme)
            <article class="thc-card @if($theme['active']) thc-card--active @endif">
                <span class="thc-swatch" style="background: linear-gradient(135deg, {{ $theme['color'] }}, {{ $theme['color_dark'] }});">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="width: 26px; height: 26px;"><path d="M12 3l1.9 5.7a2 2 0 0 0 1.3 1.3L21 12l-5.8 1.9a2 2 0 0 0-1.3 1.3L12 21l-1.9-5.8a2 2 0 0 0-1.3-1.3L3 12l5.8-2a2 2 0 0 0 1.3-1.3L12 3Z"/></svg>
                </span>

                <div class="flex-1 min-w-0">
                    <div class="thc-name-row">
                        <span class="thc-name">{{ $theme['name'] }}</span>
                        <span class="thc-ver">v{{ $theme['version'] }}</span>
                        @if($theme['active'])
                            <span class="thc-badge thc-badge--active">{{ __('admin.themes.active') }}</span>
                        @endif
                    </div>
                    @if($theme['description'] !== '')
                        <p class="thc-desc">{{ $theme['description'] }}</p>
                    @endif
                    <div class="thc-author">
                        @if($theme['author'] !== ''){{ $theme['author'] }} · @endif
                        <span class="thc-tag">themes/{{ $theme['slug'] }}</span>
                        @if($theme['has_views'])<span class="thc-tag">· {{ __('admin.themes.overrides_views') }}</span>@endif
                    </div>
                </div>

                <div class="thc-actions">
                    @if($theme['active'])
                        <span class="thc-btn" style="background: var(--gray-100, #f3f4f6); color: var(--gray-500, #6c7387); cursor: default;">{{ __('admin.themes.in_use') }}</span>
                    @else
                        <button type="button" class="thc-btn thc-btn--activate" wire:click="activate('{{ $theme['slug'] }}')">
                            {{ __('admin.themes.activate') }}
                        </button>
                        <button type="button" class="thc-btn thc-btn--danger"
                                wire:click="deleteTheme('{{ $theme['slug'] }}')"
                                wire:confirm="{{ __('admin.themes.delete_confirm', ['name' => $theme['name']]) }}">
                            {{ __('admin.themes.delete') }}
                        </button>
                    @endif
                </div>
            </article>
        @empty
            <div class="thc-empty" style="border: 1px dashed var(--gray-300, #cbd2e0); border-radius: 14px; padding: 40px; text-align: center; color: var(--gray-500, #6c7387);">
                {{ __('admin.themes.empty') }}
            </div>
        @endforelse

        <p class="thc-footer">
            💡 <span>{!! __('admin.themes.footer') !!}</span>
        </p>
    </div>
</x-filament-panels::page>
