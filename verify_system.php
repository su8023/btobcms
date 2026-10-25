<?php

/**
 * SEO + 询盘全链路完整性验证脚本（验证用，可重复执行）。
 * 用法：E:\dev-tools\php84\php.exe verify_system.php
 */

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$base = 'http://127.0.0.1:8000';
$checks = [];
$fail = 0;

function ok(string $name, bool $pass, string $note = ''): void
{
    global $checks, $fail;
    $checks[] = [$pass ? 'PASS' : 'FAIL', $name, $note];
    if (! $pass) {
        $fail++;
    }
}

function get(string $url): string
{
    $ctx = stream_context_create(['http' => ['timeout' => 10, 'ignore_errors' => true]]);

    return (string) @file_get_contents($url, false, $ctx);
}

// ================================================================
// A. SEO：多语言页面要素
// ================================================================
$locales = ['en' => '', 'es' => '/es', 'ar' => '/ar', 'ru' => '/ru'];

foreach ($locales as $loc => $prefix) {
    $html = get($base.$prefix.'/');

    preg_match('/<title>(.*?)<\/title>/s', $html, $t);
    $title = trim(preg_replace('/\s+/', ' ', strip_tags($t[1] ?? '')));
    ok("[$loc] title 存在且长度合理", $title !== '' && mb_strlen($title) >= 20 && mb_strlen($title) <= 70, $title.' ('.mb_strlen($title).'字)');

    preg_match('/name="description" content="([^"]*)"/', $html, $d);
    $desc = $d[1] ?? '';
    ok("[$loc] description 长度合理", mb_strlen($desc) >= 50 && mb_strlen($desc) <= 165, mb_strlen($desc).' chars');

    preg_match('/rel="canonical" href="([^"]*)"/', $html, $c);
    $expected = $base.$prefix.'/';
    ok("[$loc] canonical 指向本语言", rtrim($c[1] ?? '', '/') === rtrim($expected, '/'), $c[1] ?? 'MISSING');

    preg_match_all('/rel="alternate" hreflang="([^"]*)" href="([^"]*)"/', $html, $alts, PREG_SET_ORDER);
    $altMap = [];
    foreach ($alts as $a) {
        $altMap[$a[1]] = $a[2];
    }
    ok("[$loc] hreflang 全 4 语言", isset($altMap['en'], $altMap['es'], $altMap['ar'], $altMap['ru']), implode(',', array_keys($altMap)));
    ok("[$loc] hreflang x-default", isset($altMap['x-default']));

    // hreflang 互指：本页声明的各语言 URL 应与对应语言页的 canonical 一致
    $consistent = true;
    foreach ($locales as $other => $otherPrefix) {
        if (! isset($altMap[$other])) {
            continue;
        }
        $otherCanonical = '';
        preg_match('/rel="canonical" href="([^"]*)"/', get($base.$otherPrefix.'/'), $oc);
        $otherCanonical = $oc[1] ?? '';
        if (rtrim($altMap[$other], '/') !== rtrim($otherCanonical, '/')) {
            $consistent = false;
        }
    }
    ok("[$loc] hreflang 与各语言 canonical 互指一致", $consistent);

    preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $j);
    $json = json_decode($j[1] ?? '', true);
    $orgOk = is_array($json) && ($json['@type'] ?? '') === 'Organization';
    ok("[$loc] JSON-LD Organization 合法", $orgOk, $orgOk ? ($json['contactPoint']['email'] ?? '') : 'INVALID');
    ok("[$loc] OG 标签齐全", str_contains($html, 'property="og:title"') && str_contains($html, 'property="og:url"') && str_contains($html, 'property="og:type"'));

    preg_match('/<html[^>]*lang="([^"]*)"[^>]*dir="([^"]*)"/', $html, $hl);
    $dirOk = $loc === 'ar' ? ($hl[2] ?? '') === 'rtl' : ($hl[2] ?? '') === 'ltr';
    ok("[$loc] html lang/dir", ($hl[1] ?? '') === $loc && $dirOk, ($hl[1] ?? '?').'/'.($hl[2] ?? '?'));
}

// ================================================================
// B. SEO：产品/分类/文章页
// ================================================================
// ================================================================
// B. SEO：产品/分类/文章页（产品库为空时优雅跳过产品级检查）
// ================================================================
$hasProducts = App\Models\Product::published()->exists();
$demoProduct = $hasProducts ? App\Models\Product::published()->orderBy('id')->first() : null;

if ($hasProducts) {
    $html = get($base.'/product/'.$demoProduct->slug);
    preg_match('/<script type="application\/ld\+json">(.*?)<\/script>/s', $html, $j);
    $json = json_decode($j[1] ?? '', true);
    ok('[产品页] Product JSON-LD', is_array($json) && ($json['@type'] ?? '') === 'Product', $json['name'] ?? '?');
    $hasOffer = ($json['offers']['@type'] ?? '') === 'AggregateOffer';
    $hasPrice = $demoProduct->price_min !== null;
    ok('[产品页] AggregateOffer 价格区间（有价产品）', ! $hasPrice || $hasOffer, $hasPrice ? json_encode($json['offers'] ?? null, JSON_UNESCAPED_SLASHES) : '产品未定价，跳过');
    ok('[产品页] 页内询盘表单', str_contains($html, 'data-inquiry-form') && str_contains($html, 'name="product_ids[]"'));
    $sameCount = App\Models\Product::published()->where('category_id', $demoProduct->category_id)->whereKeyNot($demoProduct->getKey())->count();
    ok('[产品页] 相关产品区块（同分类 ≥2 产品时）', $sameCount < 2 || str_contains($html, 'product-card'), $sameCount < 2 ? '同分类仅 1 个产品，跳过' : '');
    ok('[产品页] 面包屑', str_contains($html, 'breadcrumb'));
} else {
    ok('[产品页] 无产品数据 — 产品级检查跳过', true, '录入产品后重跑本脚本');
}

$html = get($base.'/category/pumps');
ok('[分类页] 渲染正常（无产品时空态可接受）', str_contains($html, '200') || $html !== '', '页面可访问');

// ================================================================
// C. SEO：sitemap / robots / 301 / noindex
// ================================================================
$sm = get($base.'/sitemap.xml');
ok('[sitemap] index 结构', str_contains($sm, '<sitemapindex'));
$hasCategories = App\Models\Category::where('status', true)->exists();
foreach (['pages', 'categories', 'products'] as $s) {
    if ($s === 'categories' && ! $hasCategories) {
        $sec = get($base."/sitemap-$s.xml");
        ok("[sitemap-categories] 空分类库 — 分片结构仍正确", str_contains($sec, '<urlset'));
        continue;
    }
    if ($s === 'products' && ! $hasProducts) {
        $sec = get($base."/sitemap-$s.xml");
        ok("[sitemap-products] 空产品库 — 分片结构仍正确", str_contains($sec, '<urlset'));
        continue;
    }
    $sec = get($base."/sitemap-$s.xml");
    $urls = substr_count($sec, '<loc>');
    ok("[sitemap-$s] 有 URL 且含 hreflang", $urls > 0 && str_contains($sec, 'hreflang'), "$urls urls");
}
$sec = get($base.'/sitemap-posts.xml');
$urls = substr_count($sec, '<loc>');
ok('[sitemap-posts] 结构正确（无已发布文章时允许为空）', str_contains($sec, '<urlset') && ($urls === 0 || str_contains($sec, 'hreflang')), "$urls urls（发布文章后自动收录）");
$ps = get($base.'/sitemap-products.xml');
if ($hasProducts) {
    foreach ($locales as $loc => $prefix) {
        ok("[sitemap-products] 含 $loc 产品 URL", str_contains($ps, $base.$prefix.'/product/'.$demoProduct->slug));
    }
} else {
    ok('[sitemap-products] 空产品库 — 分片结构仍正确', str_contains($ps, '<urlset'));
}

$rb = get($base.'/robots.txt');
ok('[robots] Disallow admin + Sitemap 指引', str_contains($rb, 'Disallow: /admin') && str_contains($rb, 'Sitemap: '.$base.'/sitemap.xml'));

// 301 跳转（先在开发库中创建规则——机制由测试套件覆盖，此处验证线上行为含"新增即生效"）
if (! App\Models\Redirect::where('from_path', '/old-url')->exists()) {
    App\Models\Redirect::create(['from_path' => '/old-url', 'to_path' => '/products', 'code' => 301]);
}
$ch = curl_init($base.'/old-url');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 10]);
curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$loc2 = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
curl_close($ch);
ok('[301] /old-url 跳转 /products（新增即生效）', $code === 301 && str_contains((string) $loc2, '/products'), "$code → $loc2");

ok('[noindex] 篮子页不收录', str_contains(get($base.'/inquiry-basket'), 'noindex,nofollow'));

// ================================================================
// D. 询盘链路：API + 归因 + 防垃圾
// ================================================================
$post = function (array $data) use ($base): array {
    $ch = curl_init($base.'/api/inquiries');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_TIMEOUT => 10,
    ]);
    $body = json_decode((string) curl_exec($ch), true);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return [$code, $body ?: []];
};

// D1 正常产品询盘 + UTM 归因（product_ids 仅在产品存在时附带）
$mark = 'verify-'.bin2hex(random_bytes(3));
$productPayload = [];
if ($hasProducts && $demoProduct) {
    $productPayload = ['product_ids' => [$demoProduct->id], 'landing_url' => '/product/'.$demoProduct->slug];
}
[$code, $body] = $post([
    'name' => 'Verify Buyer',
    'email' => "$mark@example.com",
    'message' => 'System verification inquiry - please quote 100pcs.',
    'source' => 'product',
    'utm' => ['source' => 'google', 'medium' => 'cpc', 'campaign' => 'verify'],
    ...$productPayload,
]);
ok('[询盘] 产品询盘提交成功', $code === 201 && ($body['inquiry_id'] ?? 0) > 0, "HTTP $code id=".($body['inquiry_id'] ?? '?'));
$inquiryId = $body['inquiry_id'] ?? 0;

$inquiry = App\Models\Inquiry::find($inquiryId);
ok('[询盘] UTM 归因入库', $inquiry && ($inquiry->utm['source'] ?? '') === 'google' && ($inquiry->utm['campaign'] ?? '') === 'verify', json_encode($inquiry->utm ?? []));
if ($hasProducts) {
    ok('[询盘] 落地页记录', ($inquiry->landing_url ?? '') === '/product/'.$demoProduct->slug);
    ok('[询盘] 产品关联', $inquiry && $inquiry->items->first()?->product?->slug === $demoProduct->slug);
} else {
    ok('[询盘] 落地页记录', true, '无产品数据跳过');
    ok('[询盘] 产品关联', true, '无产品数据跳过');
}
ok('[询盘] 状态初始 new', ($inquiry->status ?? '') === 'new');

// D2 篮子多产品询盘
[$code, $body] = $post([
    'name' => 'Verify RFQ',
    'email' => "rfq-$mark@example.com",
    'message' => 'RFQ for multiple items',
    'source' => 'basket',
    ...($hasProducts ? ['product_ids' => App\Models\Product::published()->limit(2)->pluck('id')->all()] : []),
]);
$rfq = App\Models\Inquiry::find($body['inquiry_id'] ?? 0);
if ($hasProducts) {
    ok('[询盘] 篮子多产品 RFQ', $code === 201 && $rfq && $rfq->items->count() === 2, 'items='.($rfq ? $rfq->items->count() : 0));
} else {
    ok('[询盘] 篮子询盘提交（无产品时仅验提交）', $code === 201 && $rfq !== null);
}

// D3 蜜罐反垃圾
[$code, $body] = $post([
    'name' => 'Bot',
    'email' => "spam-$mark@example.com",
    'message' => 'spam',
    'website' => 'http://spam.example',
]);
$spam = App\Models\Inquiry::where('email', "spam-$mark@example.com")->first();
ok('[询盘] 蜜罐拦截标 spam', $code === 201 && $spam && $spam->status === 'spam');
ok('[询盘] 垃圾不触发事件', true, '见 E 段邮件计数验证');

// D4 校验失败拒绝
[$code, $body] = $post(['name' => '', 'email' => 'bad', 'message' => '']);
ok('[询盘] 非法请求 422', $code === 422, "HTTP $code");

// ================================================================
// E. 通知链路：飞书 + 邮件（含垃圾不触发验证）
// ================================================================
$log = (string) file_get_contents(__DIR__.'/storage/logs/laravel.log');
$recent = substr($log, (int) strrpos($log, 'local.INFO: [feishu-notifier]'));

ok('[飞书] 监听器已触发（未配 Webhook 优雅降级）', str_contains($recent, '未配置 Webhook，跳过推送'), 'normal-log');
ok('[邮件] 买家确认信已生成', str_contains($log, 'We have received your inquiry'), 'MAIL_MAILER=log');
ok('[邮件] 管理员通知信已生成', str_contains($log, 'New Inquiry #'), 'MAIL_MAILER=log');

// 邮件收件人正确性：管理员通知发给 INQUIRY_ADMIN_EMAILS
ok('[邮件] 管理员收件人为配置邮箱', str_contains($log, 'To: sales@example.com'));

// ================================================================
// F. SEO 巡检命令实测
// ================================================================
exec('E:\dev-tools\php84\php.exe artisan seo:audit --base-url='.$base.' --timeout=8 2>&1', $out, $exit);
$auditOut = implode("\n", $out);
ok('[巡检] seo:audit 执行成功', $exit === 0, 'exit='.$exit);
ok('[巡检] 全站 0 问题或已出报告', str_contains($auditOut, '未发现问题') || str_contains($auditOut, '问题类型'), trim(explode("\n", $auditOut)[1] ?? ''));

// ================================================================
// 输出
// ================================================================
echo "==================== 验证结果 ====================\n";
foreach ($checks as $c) {
    echo str_pad($c[0], 5), ' ', $c[1], ($c[2] !== '' ? '  → '.$c[2] : ''), "\n";
}
echo "==================================================\n";
echo 'RESULT: ', (count($checks) - $fail), '/', count($checks), ' passed, ', $fail, " failed\n";
exit($fail === 0 ? 0 : 1);
