<?php

/**
 * Google 严格标准 SEO 审计（对标 Lighthouse SEO 类目 + 富媒体结果测试 + 搜索中心指南）。
 * 在 seo_external_audit.php（基础合规 100/100）之上叠加更严格的一层：
 * 标题像素宽估算、标题层级跳跃、富媒体必填字段、E-E-A-T 信任页、404 体验、
 * 面包屑结构化、图片规范（尺寸/lazy）、sameAs、lastmod、锚文本质量。
 * 用法：php seo_strict_audit.php [base_url]
 */

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8000', '/');

function fetch(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; StrictSeoAudit/1.0; +Google-guidelines)',
    ]);
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return [(string) $body, (int) $code];
}

// 标题像素宽估算：Google 桌面 SERP 截断约 580px（按混合字符均值估算）
function titlePixelEstimate(string $s): int
{
    $px = 0;
    foreach (mb_str_split($s) as $ch) {
        if (preg_match('/[iIl1.,:;\'!|()\[\]]/u', $ch)) { $px += 4; }
        elseif (preg_match('/[mwMW@]/u', $ch)) { $px += 11; }
        elseif (preg_match('/[A-Z0-9]/u', $ch)) { $px += 8; }
        elseif (preg_match('/[\x{4e00}-\x{9fff}\x{0600}-\x{06FF}\x{0400}-\x{04FF}]/u', $ch)) { $px += 9; }
        else { $px += 6; }
    }

    return $px;
}

$issues = [];
$add = function (string $level, string $page, string $type, string $detail = '') use (&$issues): void {
    $issues[] = [$level, $page, $type, $detail];
};

echo "=== Google 严格标准审计：{$base} ===\n\n";

// ---- 主语言核心页集合（严格层逐页深检；语言变体只做抽样互检） ----
$paths = ['/', '/industries', '/industries/water-plumbing', '/industries/construction-building',
          '/services', '/about', '/contact'];

foreach ($paths as $path) {
    [$html, $code] = fetch($base.$path);

    if ($code !== 200) { $add('CRITICAL', $path, 'fetch_error', "HTTP $code"); continue; }

    // --- 1. 标题像素宽（Google 桌面 ~580px 截断）---
    preg_match('#<title>(.*?)</title>#s', $html, $t);
    $title = html_entity_decode(trim($t[1] ?? ''), ENT_QUOTES);
    $px = titlePixelEstimate($title);
    if ($px > 580) { $add('HIGH', $path, 'title_px_overflow', "{$px}px（>580 截断）"); }

    // --- 2. 标题层级跳跃（H1→H3 无 H2 等结构性问题）---
    preg_match_all('#<h([1-6])#', $html, $hs);
    $levels = array_map('intval', $hs[1]);
    $prev = 0;
    foreach ($levels as $lv) {
        if ($lv - $prev > 1 && $prev > 0) {
            $add('MEDIUM', $path, 'heading_skip', "H{$prev} → H{$lv}");
        }
        $prev = $lv === 1 ? 1 : max($prev, $lv);
    }
    if (! in_array(1, $levels, true)) { $add('HIGH', $path, 'no_h1', ''); }

    // --- 3. 富媒体结果必填字段（Google Rich Results 规则）---
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $jl);
    $hasBreadcrumb = false;
    foreach ($jl[1] as $block) {
        $nodes = json_decode(trim($block), true);
        if ($nodes === null) { $add('HIGH', $path, 'jsonld_parse_fail', ''); continue; }
        $nodes = isset($nodes[0]) ? $nodes : [$nodes];
        foreach ($nodes as $n) {
            $type = $n['@type'] ?? '';
            // Organization：logo 必须可解析为图片 URL；sameAs 推荐（E-E-A-T）
            if ($type === 'Organization') {
                $logo = $n['logo'] ?? '';
                if ($logo === '') { $add('HIGH', $path, 'org_logo_missing', ''); }
                elseif (str_ends_with($logo, '.svg')) { $add('MEDIUM', $path, 'org_logo_svg', 'Google 建议光栅图 ≥112px（SVG 兼容性差）'); }
                if (empty($n['sameAs'])) { $add('LOW', $path, 'org_sameas_missing', '社交资料 E-E-A-T 信号'); }
            }
            // Service：provider 必填
            if ($type === 'Service' && empty($n['provider'])) { $add('HIGH', $path, 'service_provider_missing', ''); }
            // FAQPage：问题与答案非空且页面上可见
            if ($type === 'FAQPage') {
                foreach ($n['mainEntity'] ?? [] as $q) {
                    if (empty($q['name']) || empty($q['acceptedAnswer']['text'][0] ?? '')) {
                        $add('HIGH', $path, 'faq_empty_entry', '');
                    }
                }
                // 页面可见性：FAQ 文本应出现在 HTML 里（Google 要求 schema 与可见内容一致）
                foreach ($n['mainEntity'] ?? [] as $q) {
                    $needle = mb_substr($q['name'] ?? '', 0, 30);
                    if ($needle && ! str_contains($html, htmlspecialchars($needle, ENT_QUOTES))
                        && ! str_contains($html, $needle)) {
                        $add('HIGH', $path, 'faq_not_visible', mb_substr($needle, 0, 40));
                    }
                }
            }
            if ($type === 'BreadcrumbList') { $hasBreadcrumb = true; }
        }
    }
    // 深层页应有面包屑结构化（Google 指南：表现站点层级）
    if (preg_match('#^/industries/#', $path) && ! $hasBreadcrumb) {
        $add('MEDIUM', $path, 'breadcrumb_schema_missing', '行业落地页应有 BreadcrumbList');
    }

    // --- 4. 图片规范（CLS 与无障碍）---
    preg_match_all('#<img[^>]*>#', $html, $imgs);
    foreach ($imgs[0] as $tag) {
        if (! preg_match('#width=#', $tag) || ! preg_match('#height=#', $tag)) {
            $add('MEDIUM', $path, 'img_no_dimensions', 'CLS 风险');
        }
        if (! preg_match('#loading="lazy"#', $tag) && ! preg_match('#fetchpriority="high"#', $tag)) {
            $add('LOW', $path, 'img_no_lazy', '');
        }
        if (! preg_match('#alt="[^"]+"#', $tag)) { $add('MEDIUM', $path, 'img_no_alt', ''); }
    }

    // --- 5. 移动优先（视口 + 字号下限 + 触控目标由 CSS 控制，此处查视口与禁缩放）---
    if (preg_match('#name="viewport"[^>]*maximum-scale|user-scalable=no#', $html)) {
        $add('HIGH', $path, 'viewport_zoom_blocked', '禁用缩放影响无障碍');
    }

    // --- 6. 链接质量（裸 URL/泛化锚文本）---
    preg_match_all('#<a[^>]*>(.*?)</a>#s', $html, $as);
    foreach ($as[1] as $anchor) {
        $text = trim(strip_tags($anchor));
        if ($text === 'here' || $text === 'click here' || preg_match('#^https?://#', $text)) {
            $add('LOW', $path, 'weak_anchor', mb_substr($text, 0, 30));
        }
    }

    // --- 7. 语言声明与内容一致（ar 页应 rtl——抽样在语言层查）---
}

// ---- 语言变体抽样（西语/阿语各 1 页：dir、TDK 独立、hreflang 自指）----
foreach (['/es/industries', '/ar/industries/water-plumbing'] as $path) {
    [$html, $code] = fetch($base.$path);
    if ($code !== 200) { $add('HIGH', $path, 'fetch_error', "HTTP $code"); continue; }
    if ($path === '/ar/industries/water-plumbing' && ! str_contains($html, 'dir="rtl"')) {
        $add('HIGH', $path, 'rtl_missing', '');
    }
    preg_match('#<title>(.*?)</title>#s', $html, $t);
    if (str_contains($t[1] ?? '', 'industries.') || str_contains($t[1] ?? '', 'site.')) {
        $add('HIGH', $path, 'untranslated_tdk', mb_substr($t[1] ?? '', 0, 40));
    }
}

// ---- E-E-A-T 信任基建：隐私政策 / 条款 / 404 ----
[$privacy, $pc] = fetch($base.'/privacy');
if ($pc !== 200) { $add('HIGH', '/', 'privacy_page_missing', "HTTP {$pc}（GDPR 必备）"); }
[$terms, $tc] = fetch($base.'/terms');
if ($tc !== 200) { $add('HIGH', '/', 'terms_page_missing', "HTTP {$tc}"); }

[, $c404] = fetch($base.'/definitely-not-a-page');
if ($c404 !== 404) { $add('HIGH', '/', '404_wrong_status', "HTTP {$c404} 应为 404"); }
else {
    // 404 页应有导航回首页（用户体验）
    [$e404] = fetch($base.'/definitely-not-a-page');
    if (! str_contains($e404, 'Home') && ! str_contains($e404, 'href="'.$base.'"')) {
        $add('MEDIUM', '/', '404_no_nav', '默认 404 无导航');
    }
}

// ---- sitemap lastmod 覆盖（Google：提供真实 lastmod 利于抓取调度）----
foreach (['industries'] as $sec) {
    [$xml] = fetch($base."/sitemap-{$sec}.xml");
    $urls = substr_count($xml, '<url>');
    $lastmods = substr_count($xml, '<lastmod>');
    if ($urls > 0 && $lastmods === 0) {
        $add('LOW', "sitemap-{$sec}", 'no_lastmod', "$urls URLs 无 lastmod");
    }
}

// ---- 汇总 ----
echo "=== 问题明细（严格层；基础合规层已 100/100） ===\n";
$weight = ['CRITICAL' => 25, 'HIGH' => 8, 'MEDIUM' => 3, 'LOW' => 1];
$order = ['CRITICAL' => 0, 'HIGH' => 1, 'MEDIUM' => 2, 'LOW' => 3];
usort($issues, fn ($a, $b) => $order[$a[0]] <=> $order[$b[0]]);
$byType = [];
$deduct = 0;
foreach ($issues as [$level, $page, $type, $detail]) {
    echo sprintf("  [%-8s] %-26s %-42s %s\n", $level, $type, $page, $detail);
    $byType[$type] = ($byType[$type] ?? 0) + 1;
    $deduct += $weight[$level];
}
if ($issues === []) { echo "  （无问题）\n"; }
$score = max(0, 100 - $deduct);
echo "\n=== 类型统计 ===\n";
foreach ($byType as $type => $n) { echo sprintf("  %-26s ×%d\n", $type, $n); }
echo "\n=== 严格层评分: {$score}/100 ===\n";
