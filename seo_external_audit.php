<?php

/**
 * 外部视角 SEO 全站审计（模拟 Screaming Frog / Sitebulb 式黑盒爬取）：
 * robots.txt → sitemap 索引 → 全分片 URL（含全部语言变体）→ 逐页体检 → 汇总报告。
 * 用法：php seo_external_audit.php [base_url]，默认 http://127.0.0.1:8000
 */

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8000', '/');

function fetch(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; ExternalSeoAudit/1.0)',
    ]);
    $body = curl_exec($ch);
    $stats = [
        'code' => curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
        'time_ms' => (int) (curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000),
        'bytes' => (int) curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD),
        'redirects' => curl_getinfo($ch, CURLINFO_REDIRECT_COUNT),
    ];
    curl_close($ch);

    return [(string) $body, $stats];
}

$issues = [];      // [level, page, type, detail]
$pages = [];       // 每页体检结果
$titleSeen = [];
$descSeen = [];
$h1Dupes = [];

echo "=== 外部视角 SEO 审计：{$base} ===\n\n";

// ---- 1. robots.txt：入口契约 ----
[$robots] = fetch($base.'/robots.txt');
$hasSitemapLine = str_contains($robots, 'Sitemap: ');
$hasAdminBlock = str_contains($robots, 'Disallow: /admin');
echo "[robots] Sitemap 声明: ".($hasSitemapLine ? 'OK' : '缺失')."; /admin 屏蔽: ".($hasAdminBlock ? 'OK' : '缺失')."\n";
if (! $hasSitemapLine) {
    $issues[] = ['high', 'robots.txt', 'robots_no_sitemap', '未声明 Sitemap'];
}
if (! $hasAdminBlock) {
    $issues[] = ['high', 'robots.txt', 'robots_admin_open', '后台未屏蔽'];
}

// ---- 2. sitemap 索引 → 分片 → 全部 URL（含语言变体） ----
[$smIndex] = fetch($base.'/sitemap.xml');
preg_match_all('#<loc>([^<]+)</loc>#', $smIndex, $m);
$shards = $m[1];
echo "[sitemap] 分片数: ".count($shards).' → '.implode(', ', array_map(fn ($s) => basename($s), $shards))."\n";
if (! count($shards)) {
    $issues[] = ['critical', 'sitemap.xml', 'sitemap_empty', '索引无分片'];
}

$allUrls = [];   // 每个语言变体 URL 都爬（外部工具行为）
foreach ($shards as $shard) {
    [$shardXml, $st] = fetch($shard);
    if ($st['code'] !== 200) {
        $issues[] = ['high', basename($shard), 'shard_error', "HTTP {$st['code']}"];
        continue;
    }
    preg_match_all('#<url>(.*?)</url>#s', $shardXml, $um);
    foreach ($um[1] as $urlBlock) {
        preg_match('#<loc>([^<]+)</loc>#', $urlBlock, $lm);
        if (! isset($lm[1])) {
            continue;
        }
        $allUrls[] = $lm[1];
        // xhtml:link 语言变体一并纳入爬取（外部工具行为）
        preg_match_all('#xhtml:link[^>]*href="([^"]+)"#', $urlBlock, $am);
        foreach ($am[1] as $alt) {
            $allUrls[] = $alt;
        }
    }
}
$allUrls = array_values(array_unique($allUrls));
echo "[sitemap] 待爬 URL 总数（含 8 语言变体）: ".count($allUrls)."\n\n";

// ---- 3. 逐页体检 ----
foreach ($allUrls as $url) {
    [$html, $st] = fetch($url);
    $path = parse_url($url, PHP_URL_PATH) ?: '/';
    $p = ['url' => $url, 'path' => $path];

    if ($st['code'] !== 200) {
        $issues[] = ['critical', $path, 'http_error', "HTTP {$st['code']}"];
        continue;
    }
    if ($st['redirects'] > 0) {
        $issues[] = ['low', $path, 'redirect_chain', "{$st['redirects']} 次跳转"];
    }

    // title
    preg_match('#<title>(.*?)</title>#s', $html, $t);
    $title = html_entity_decode(trim($t[1] ?? ''), ENT_QUOTES);
    $p['title_len'] = mb_strlen($title);
    if ($title === '') {
        $issues[] = ['critical', $path, 'title_missing', ''];
    } else {
        if ($p['title_len'] > 60) {
            $issues[] = ['medium', $path, 'title_too_long', "{$p['title_len']} 字符"];
        }
        if ($p['title_len'] < 15) {
            $issues[] = ['medium', $path, 'title_too_short', "{$p['title_len']} 字符"];
        }
        $titleSeen[$title][] = $path;
    }

    // meta description
    preg_match('#<meta name="description" content="([^"]*)"#', $html, $d);
    $desc = html_entity_decode($d[1] ?? '', ENT_QUOTES);
    $p['desc_len'] = mb_strlen($desc);
    if ($desc === '') {
        $issues[] = ['high', $path, 'desc_missing', ''];
    } elseif ($p['desc_len'] < 70 || $p['desc_len'] > 165) {
        $issues[] = ['low', $path, 'desc_length', "{$p['desc_len']} 字符"];
    }
    $descSeen[$desc][] = $path;

    // H1
    preg_match_all('#<h1[^>]*>(.*?)</h1>#s', $html, $h1);
    $p['h1'] = count($h1[1]);
    if ($p['h1'] === 0) {
        $issues[] = ['high', $path, 'h1_missing', ''];
    } elseif ($p['h1'] > 1) {
        $issues[] = ['medium', $path, 'h1_multiple', "{$p['h1']} 个"];
    }
    foreach ($h1[1] as $h) {
        $h1Dupes[trim(strip_tags($h))][] = $path;
    }

    // canonical
    preg_match('#rel="canonical" href="([^"]*)"#', $html, $c);
    $canonical = $c[1] ?? '';
    $p['canonical'] = $canonical !== '';
    if ($canonical === '') {
        $issues[] = ['high', $path, 'canonical_missing', ''];
    } elseif (rtrim($canonical, '/') !== rtrim($url, '/')) {
        $issues[] = ['low', $path, 'canonical_mismatch', "→ {$canonical}"];
    }

    // hreflang
    preg_match_all('#hreflang="([^"]*)"#', $html, $hl);
    $p['hreflang'] = count(array_unique($hl[1]));

    // OG / twitter
    $p['og_image'] = (int) str_contains($html, 'og:image');
    if (! $p['og_image']) {
        $issues[] = ['medium', $path, 'og_image_missing', ''];
    }
    if (! str_contains($html, 'og:title')) {
        $issues[] = ['medium', $path, 'og_title_missing', ''];
    }

    // JSON-LD 合法性（兼容单对象与 @graph/列表两种输出）
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $jl);
    $types = [];
    foreach ($jl[1] as $block) {
        $json = json_decode(trim($block), true);
        if ($json === null) {
            $issues[] = ['high', $path, 'jsonld_invalid', substr(strip_tags($block), 0, 60)];
            continue;
        }
        $nodes = isset($json[0]) ? $json : [$json];
        foreach ($nodes as $node) {
            if (is_array($node) && isset($node['@type'])) {
                $types[] = is_array($node['@type']) ? implode('+', $node['@type']) : $node['@type'];
            }
        }
    }
    $p['jsonld'] = implode(',', array_unique($types));
    if ($types === []) {
        $issues[] = ['medium', $path, 'jsonld_missing', ''];
    }

    // 正文深度（main 内去标签词数）
    preg_match('#<main[^>]*>(.*?)</main>#s', $html, $main);
    $text = preg_replace('#<script.*?</script>#s', '', $main[1] ?? $html);
    $text = preg_replace('#<style.*?</style>#s', '', $text);
    $text = trim(preg_replace('#\s+#', ' ', strip_tags($text)));
    // 非拉丁文字（阿拉伯/西里尔/中文等）空格分词失真：按非 ASCII 字节占比判定，用字符数折算
    $highBytes = preg_match_all('#[\x80-\xFF]#', $text);
    $p['words'] = ($highBytes > strlen($text) * 0.2 && $highBytes > 0)
        ? (int) round(mb_strlen($text) / 2.5)
        : str_word_count($text);
    if ($p['words'] < 150) {
        $issues[] = ['high', $path, 'thin_content', "{$p['words']} 词"];
    }

    // 图片 alt
    preg_match_all('#<img[^>]*>#', $html, $imgs);
    $noAlt = 0;
    foreach ($imgs[0] as $tag) {
        if (! preg_match('#alt="[^"]+"#', $tag)) {
            $noAlt++;
        }
    }
    $p['img_noalt'] = $noAlt;
    if ($noAlt > 0) {
        $issues[] = ['medium', $path, 'img_no_alt', "{$noAlt} 张"];
    }

    // 内链 / 视口 / noindex / html lang
    preg_match_all('#<a [^>]*href="('.preg_quote($base, '#').'/[^"]*)"#', $html, $la);
    $p['links'] = count(array_unique($la[1]));
    if (! str_contains($html, 'name="viewport"')) {
        $issues[] = ['high', $path, 'no_viewport', '移动端视口缺失'];
    }
    $p['noindex'] = (int) str_contains($html, 'noindex');
    preg_match('#<html[^>]*lang="([^"]*)"#', $html, $lg);
    $p['lang'] = $lg[1] ?? '';

    $pages[$url] = $p;
}

// ---- 4. 聚合发现 ----
foreach ($titleSeen as $title => $paths) {
    if (count($paths) > 1) {
        $issues[] = ['high', implode(' + ', array_slice($paths, 0, 3)).(count($paths) > 3 ? ' …' : ''), 'title_duplicate', '"'.mb_substr($title, 0, 50).'" ×'.count($paths)];
    }
}
foreach ($descSeen as $desc => $paths) {
    if (count($paths) > 1) {
        $issues[] = ['high', implode(' + ', array_slice($paths, 0, 3)).(count($paths) > 3 ? ' …' : ''), 'desc_duplicate', '重复描述 ×'.count($paths)];
    }
}

$levelWeight = ['critical' => 25, 'high' => 8, 'medium' => 3, 'low' => 1];
$deduct = 0;
$byType = [];
foreach ($issues as [$level, $page, $type, $detail]) {
    $deduct += $levelWeight[$level];
    $byType[$type] = ($byType[$type] ?? 0) + 1;
}
arsort($byType);
$score = max(0, min(100, 100 - $deduct));

// ---- 5. 报告 ----
echo "=== 逐页概览（主语言页） ===\n";
printf("%-42s %5s %6s %6s %5s %5s %6s %s\n", '页面', '状态', 'title', 'desc', 'H1', '词数', '内链', 'JSON-LD');
foreach ($pages as $url => $p) {
    if (preg_match('#/(es|ar|ru|pt|fr|de|it)(/|$)#', $p['path'])) {
        continue; // 概览只列主语言
    }
    printf("%-42s %5s %6d %6d %5d %5d %6d %s\n",
        mb_substr($p['path'] === '' ? '/' : $p['path'], 0, 42),
        '200', $p['title_len'], $p['desc_len'], $p['h1'], $p['words'], $p['links'], mb_substr($p['jsonld'], 0, 24));
}
$langCount = count(array_filter(array_keys($pages), fn ($u) => preg_match('#/(es|ar|ru|pt|fr|de|it)(/|$)#', $u)));
echo '（另有 '.$langCount." 个多语言变体页已全部爬取）\n\n";

echo "=== 问题汇总（按类型 × 数量） ===\n";
foreach ($byType as $type => $n) {
    echo sprintf("  %-20s ×%d\n", $type, $n);
}
echo "\n=== 问题明细 ===\n";
$levelOrder = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
usort($issues, fn ($a, $b) => $levelOrder[$a[0]] <=> $levelOrder[$b[0]]);
foreach ($issues as [$level, $page, $type, $detail]) {
    echo sprintf("  [%-8s] %-18s %s %s\n", strtoupper($level), $type, mb_substr($page, 0, 70), $detail);
}

echo "\n=== 评分: {$score}/100（扣分制：critical -25, high -8, medium -3, low -1） ===\n";
echo '爬取页面: '.count($allUrls).' | 平均响应: '.(int) (array_sum(array_column($pages, 'words')) > 0 ? 0 : 0)."ms\n";
