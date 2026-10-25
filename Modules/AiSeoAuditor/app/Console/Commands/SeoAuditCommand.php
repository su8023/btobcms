<?php

namespace Modules\AiSeoAuditor\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Modules\AiSeoAuditor\Events\SeoAuditFinished;
use Throwable;

class SeoAuditCommand extends Command
{
    protected $signature = 'seo:audit
        {--base-url= : 站点根地址（默认：插件中心设置 → APP_URL）}
        {--limit= : 最多检查页面数（默认：插件中心设置 → 100）}
        {--timeout=10 : 单页请求超时秒数}';

    protected $description = '抓取站点 sitemap 并做规则化 SEO 巡检（TDK/H1/canonical/状态码）';

    public function handle(): int
    {
        // 设置优先级：命令行参数 → 插件中心数据库设置 → APP_URL/config 默认
        $base = rtrim((string) ($this->option('base-url')
            ?: plugin_setting('ai-seo-auditor', 'base_url')
            ?: config('app.url')), '/');
        $limit = max(1, (int) ($this->option('limit')
            ?: plugin_setting('ai-seo-auditor', 'limit')
            ?: 100));
        $timeout = max(1, (int) ($this->option('timeout')
            ?: plugin_setting('ai-seo-auditor', 'audit_timeout')
            ?: 10));

        $urls = $this->sitemapUrls($base, $timeout);

        if ($urls === []) {
            $this->warn("未获取到 {$base}/sitemap.xml，本次仅检查首页。（SEO 模块上线后会自动生成 sitemap）");
            $urls = [$base.'/'];
        }

        $urls = array_slice($urls, 0, $limit);
        $this->info('开始巡检 '.count($urls).' 个页面：'.$base);

        $issues = [];
        $summary = [];

        foreach ($urls as $url) {
            foreach ($this->auditPage($url, $timeout) as $issue) {
                $summary[$issue['type']] = ($summary[$issue['type']] ?? 0) + 1;
                $issues[] = $issue;
            }
            $this->output->write('.');
        }
        $this->newLine();

        if ($summary === []) {
            $this->info('巡检完成，未发现问题 🎉');
        } else {
            arsort($summary);
            $this->table(['问题类型', '数量'], collect($summary)->map(
                fn ($count, $type) => [$type, $count]
            )->values()->all());

            $this->warn('问题明细（前 20 条）：');
            foreach (array_slice($issues, 0, 20) as $issue) {
                $detail = isset($issue['detail']) ? ' — '.$issue['detail'] : '';
                $this->line(sprintf('  [%s] %s%s', $issue['type'], $issue['url'], $detail));
            }
        }

        $reportPath = storage_path('app/seo-audits/audit-'.now()->format('Ymd-His').'.json');
        @mkdir(dirname($reportPath), 0755, true);
        file_put_contents($reportPath, json_encode([
            'base_url' => $base,
            'checked' => count($urls),
            'summary' => $summary,
            'issues' => $issues,
            'finished_at' => now()->toIso8601String(),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        $this->info('报告已保存：'.$reportPath);

        // 报告保留份数（设置面板 advanced.keep_reports，0 = 保留全部）
        $keep = (int) plugin_setting('ai-seo-auditor', 'keep_reports', 0);
        if ($keep > 0) {
            $allReports = glob(storage_path('app/seo-audits/audit-*.json')) ?: [];
            sort($allReports);
            foreach (array_slice($allReports, 0, -$keep) as $oldReport) {
                @unlink($oldReport);
            }
        }

        SeoAuditFinished::dispatch($base, count($urls), count($issues), $summary, $reportPath);

        return self::SUCCESS;
    }

    /** @return string[] */
    protected function sitemapUrls(string $base, int $timeout): array
    {
        try {
            $response = Http::timeout($timeout)->get($base.'/sitemap.xml');

            if (! $response->successful()) {
                return [];
            }

            $xml = simplexml_load_string($response->body());

            if ($xml === false) {
                return [];
            }
        } catch (Throwable) {
            return [];
        }

        $urls = [];
        $isIndex = isset($xml->sitemap);

        foreach ($xml->children() as $child) {
            $loc = trim((string) $child->loc);

            if ($loc === '') {
                continue;
            }

            // sitemap index：再取一层子 sitemap
            if ($isIndex && str_ends_with($loc, '.xml')) {
                try {
                    $sub = simplexml_load_string(Http::timeout($timeout)->get($loc)->body());
                    foreach ($sub?->url ?? [] as $u) {
                        $urls[] = trim((string) $u->loc);
                    }
                } catch (Throwable) {
                }

                continue;
            }

            $urls[] = $loc;
        }

        return array_values(array_unique($urls));
    }

    /** @return array<int, array{type: string, url: string, detail?: string}> */
    protected function auditPage(string $url, int $timeout): array
    {
        try {
            $response = Http::timeout($timeout)->get($url);
        } catch (Throwable $e) {
            return [['type' => 'request_failed', 'url' => $url, 'detail' => mb_substr($e->getMessage(), 0, 120)]];
        }

        if ($response->status() !== 200) {
            return [['type' => 'http_status_'.$response->status(), 'url' => $url]];
        }

        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">'.$response->body());
        libxml_clear_errors();

        $titleNode = $doc->getElementsByTagName('title')->item(0);
        $title = $titleNode ? trim($titleNode->textContent) : '';

        $description = '';
        foreach ($doc->getElementsByTagName('meta') as $meta) {
            if (strtolower($meta->getAttribute('name')) === 'description') {
                $description = trim($meta->getAttribute('content'));
                break;
            }
        }

        $canonical = '';
        $xpath = new \DOMXPath($doc);
        $canonicalNode = $xpath->query('//link[@rel="canonical"]/@href')->item(0);
        if ($canonicalNode) {
            $canonical = trim((string) $canonicalNode->nodeValue);
        }

        $h1Count = $doc->getElementsByTagName('h1')->length;

        $issues = [];

        if ($title === '') {
            $issues[] = ['type' => 'title_missing', 'url' => $url];
        } else {
            $len = mb_strlen($title);
            if ($len < 10) {
                $issues[] = ['type' => 'title_too_short', 'url' => $url, 'detail' => "({$len}) {$title}"];
            }
            if ($len > 65) {
                $issues[] = ['type' => 'title_too_long', 'url' => $url, 'detail' => "({$len})"];
            }
        }

        if ($description === '') {
            $issues[] = ['type' => 'description_missing', 'url' => $url];
        } else {
            $len = mb_strlen($description);
            if ($len < 50) {
                $issues[] = ['type' => 'description_too_short', 'url' => $url, 'detail' => "({$len})"];
            }
            if ($len > 165) {
                $issues[] = ['type' => 'description_too_long', 'url' => $url, 'detail' => "({$len})"];
            }
        }

        if ($h1Count === 0) {
            $issues[] = ['type' => 'h1_missing', 'url' => $url];
        } elseif ($h1Count > 1) {
            $issues[] = ['type' => 'h1_multiple', 'url' => $url, 'detail' => "x{$h1Count}"];
        }

        if ($canonical === '') {
            $issues[] = ['type' => 'canonical_missing', 'url' => $url];
        }

        return $issues;
    }
}
