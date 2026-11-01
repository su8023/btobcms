{{-- SEO 体检弹窗内容（Filament Action modalContent）：按语言分卡片，指标芯片 + 问题清单 --}}
@php($report = $report ?? ['checked' => false, 'summary' => ['checked' => 0, 'problems' => 0], 'locales' => []])

<div class="seo-check">
    @if (! $report['checked'])
        <div class="seo-issues" style="padding: calc(var(--spacing) * 2);">
            <li style="list-style: none;">还没有可检查的语言内容——先在表单里填好标题或正文。</li>
        </div>
    @elseif ($report['summary']['problems'] === 0)
        <div class="seo-check__allpass">
            ✅ {{ $report['summary']['checked'] }} 种语言的内容全部通过，可以发布。
        </div>
    @else
        <p class="text-sm" style="color: var(--gray-500);">
            {{ $report['summary']['checked'] }} 种语言中，共 {{ $report['summary']['problems'] }} 项待修复：
        </p>
    @endif

    @foreach ($report['locales'] as $locale)
        <div class="seo-locale">
            <div class="seo-locale__head">
                <span class="seo-locale__name">{{ $locale['label'] }}</span>
                @if ($locale['problems'] === [])
                    <span class="seo-badge seo-badge--ok">通过</span>
                @else
                    <span class="seo-badge seo-badge--warn">{{ count($locale['problems']) }} 项待修复</span>
                @endif
            </div>
            <div class="seo-locale__stats">
                <span class="seo-chip {{ $locale['stats']['words']['ok'] ? 'seo-chip--ok' : 'seo-chip--bad' }}">
                    <span class="seo-chip__dot"></span>
                    <span class="seo-chip__label">正文</span>
                    <span class="seo-chip__value">{{ $locale['stats']['words']['value'] }}</span>
                </span>
                <span class="seo-chip {{ $locale['stats']['h2']['ok'] ? 'seo-chip--ok' : 'seo-chip--bad' }}">
                    <span class="seo-chip__dot"></span>
                    <span class="seo-chip__label">H2</span>
                    <span class="seo-chip__value">{{ $locale['stats']['h2']['value'] }}</span>
                </span>
                <span class="seo-chip {{ $locale['stats']['links']['ok'] ? 'seo-chip--ok' : 'seo-chip--bad' }}">
                    <span class="seo-chip__dot"></span>
                    <span class="seo-chip__label">内链</span>
                    <span class="seo-chip__value">{{ $locale['stats']['links']['value'] }}</span>
                </span>
                @if ($locale['stats']['images'] !== null)
                    <span class="seo-chip {{ $locale['stats']['images']['ok'] ? 'seo-chip--ok' : 'seo-chip--bad' }}">
                        <span class="seo-chip__dot"></span>
                        <span class="seo-chip__label">图片 alt</span>
                        <span class="seo-chip__value">{{ $locale['stats']['images']['value'] }}</span>
                    </span>
                @endif
                <span class="seo-chip {{ $locale['stats']['seo']['ok'] ? 'seo-chip--ok' : 'seo-chip--bad' }}">
                    <span class="seo-chip__dot"></span>
                    <span class="seo-chip__label">SEO 元数据</span>
                    <span class="seo-chip__value">{{ $locale['stats']['seo']['value'] }}</span>
                </span>
            </div>
            @if ($locale['problems'] !== [])
                <div class="seo-locale__body">
                    <ul class="seo-issues">
                        @foreach ($locale['problems'] as $problem)
                            <li>{{ $problem }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    @endforeach
</div>
