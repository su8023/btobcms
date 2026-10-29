{{-- TrustBuilder 全站样式（Provider 注入 head 插槽，布局 </head> 前渲染） --}}
{{-- 复用站内 section/container/grid/breadcrumb/article/btn 类，这里只补案例卡片、图集与信任条的专属样式 --}}
<style>
    /* ── 案例卡片（列表页 + 详情页相关案例） ── */
    .trust-case-card {
        display: flex;
        flex-direction: column;
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius-lg);
        overflow: hidden;
        box-shadow: var(--shadow-sm);
        text-decoration: none;
        color: var(--ink);
        transition: box-shadow .25s ease, transform .25s ease, border-color .25s ease;
    }
    .trust-case-card:hover {
        box-shadow: var(--shadow-lg);
        transform: translateY(-4px);
        border-color: var(--primary-100);
        color: var(--ink);
    }
    .trust-case-card__media {
        display: block;
        aspect-ratio: 4 / 3;
        background: linear-gradient(160deg, var(--primary-soft), #d5e5f7);
        overflow: hidden;
    }
    .trust-case-card__media img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform .45s cubic-bezier(.2, .6, .3, 1);
    }
    .trust-case-card:hover .trust-case-card__media img { transform: scale(1.06); }
    .trust-case-card__body {
        display: flex;
        flex-direction: column;
        gap: 5px;
        padding: 16px;
        flex: 1;
    }
    .trust-case-card__customer {
        font-size: var(--fs-sm);
        font-weight: 650;
        color: var(--primary);
        letter-spacing: .02em;
    }
    .trust-case-card__meta {
        font-size: var(--fs-xs);
        color: var(--muted);
    }
    .trust-case-card__title {
        font-size: var(--fs-base);
        font-weight: 700;
        margin: 2px 0 0;
    }
    .trust-case-card__more {
        margin-top: auto;
        padding-top: 8px;
        font-size: var(--fs-sm);
        color: var(--primary);
        font-weight: 600;
    }

    /* ── 详情页 meta 徽章 ── */
    .trust-case-meta {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 18px;
    }
    .trust-case-meta__chip {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 3px 10px;
        border: 1px solid var(--border);
        border-radius: 999px;
        background: var(--surface);
        font-size: var(--fs-sm);
        color: var(--muted);
    }
    .trust-case-meta__chip strong { color: var(--ink); font-weight: 650; }

    /* ── 详情页图集 ── */
    .trust-case-gallery {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
        margin-top: 24px;
    }
    .trust-case-gallery img {
        width: 100%;
        aspect-ratio: 4 / 3;
        object-fit: cover;
        border-radius: var(--radius);
        border: 1px solid var(--border);
    }
    @media (max-width: 640px) {
        .trust-case-gallery { grid-template-columns: 1fr; }
    }

    /* ── 全站信任条（footer_before 插槽） ── */
    .trust-bar {
        background: var(--surface);
        border-block: 1px solid var(--border);
        padding: 18px 0;
    }
    .trust-bar__inner {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 10px 18px;
    }
    .trust-bar__label {
        font-size: var(--fs-sm);
        font-weight: 700;
        color: var(--muted);
        letter-spacing: .04em;
        text-transform: uppercase;
    }
    .trust-bar__badges {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        list-style: none;
        margin: 0;
        padding: 0;
    }
    .trust-bar__badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 12px;
        border: 1px solid var(--border-strong);
        border-radius: 999px;
        background: var(--bg, #fff);
        font-size: var(--fs-sm);
        font-weight: 600;
        color: var(--ink);
    }
    .trust-bar__badge::before { content: "✔"; color: var(--primary); font-size: .85em; }
    .trust-bar__link {
        margin-inline-start: auto;
        font-size: var(--fs-sm);
        font-weight: 650;
        color: var(--primary);
        text-decoration: none;
        white-space: nowrap;
    }
    .trust-bar__link:hover { text-decoration: underline; text-underline-offset: 3px; }
    @media (max-width: 640px) {
        .trust-bar__link { margin-inline-start: 0; }
    }
</style>
