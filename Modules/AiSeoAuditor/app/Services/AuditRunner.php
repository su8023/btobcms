<?php

namespace Modules\AiSeoAuditor\Services;

use Illuminate\Support\Facades\Http;
use Modules\AiSeoAuditor\Events\SeoAuditFinished;
use Modules\AiSeoAuditor\Support\SafeUrl;
use Throwable;

/**
 * SEO 巡检执行器：抓 sitemap → 逐页体检 → 落报告 → 发事件。
 *
 * 命令（php artisan seo:audit）与后台「立即巡检」（队列任务）共用本类，
 * 保证两条路径行为一致。
 *
 * 安全：sitemap 里的 <loc> 是外部可控数据，直接拿去请求会让服务器变成
 * 内网 / 云元数据的跳板 —— 所有实际发出的请求都要先过 SafeUrl 这道闸。
 */
class AuditRunner
{
    /** 子 sitemap 展开上限：sitemap index 里可能有成千上万个分片 */
    public const MAX_SUB_SITEMAPS = 50;

    /**
     * 执行一次巡检。
     *
     * @return array{report_path: string, checked: int, issue_count: int, summary: array<string, int>, skipped: int}
     */
    public function run(string $base, int $limit, int $timeout): array
    {
        $base = rtrim($base, '/');
        $limit = max(1, $limit);
        $timeout = max(1, $timeout);

        $guard = new SafeUrl($base);

        $collected = $this->sitemapUrls($base, $timeout, $guard, $limit);

        $urls = $collected['urls'];
        $skipped = $collected['skipped'];

        if ($urls === []) {
            $urls = [$base.'/'];
        }

        $urls = array_slice($urls, 0, $limit);

        $issues = [];
        $summary = [];

        foreach ($urls as $url) {
            foreach ($this->auditPage($url, $timeout, $guard) as $issue) {
                $summary[$issue['type']] = ($summary[$issue['type']] ?? 0) + 1;
                $issues[] = $issue;
            }
        }

        $reportPath = $this->writeReport($base, count($urls), $summary, $issues);

        SeoAuditFinished::dispatch($base, count($urls), count($issues), $summary, $reportPath);

        return [
            'report_path' => $reportPath,
            'checked' => count($urls),
            'issue_count' => count($issues),
            'summary' => $summary,
            'skipped' => $skipped,
        ];
    }

    /**
     * 读取 sitemap（索引 + 分片），返回页面 URL 列表。
     *
     * @return array{urls: list<string>, skipped: int}
     */
    protected function sitemapUrls(string $base, int $timeout, SafeUrl $guard, int $limit): array
    {
        try {
            $response = Http::timeout($timeout)->get($base.'/sitemap.xml');

            if (! $response->successful()) {
                return ['urls' => [], 'skipped' => 0];
            }

            $xml = simplexml_load_string($response->body());

            if ($xml === false) {
                return ['urls' => [], 'skipped' => 0];
            }
        } catch (Throwable) {
            return ['urls' => [], 'skipped' => 0];
        }

        $urls = [];
        $skipped = 0;
        $isIndex = isset($xml->sitemap);
        $subSitemaps = 0;

        foreach ($xml->children() as $child) {
            $loc = trim((string) $child->loc);

            if ($loc === '') {
                continue;
            }

            // sitemap index：再取一层子 sitemap（但要限流，否则一个恶意 index 能让服务器发 thousands 次请求）
            if ($isIndex && str_ends_with($loc, '.xml')) {
                if ($subSitemaps >= self::MAX_SUB_SITEMAPS) {
                    $skipped++;

                    continue;
                }

                if (! $guard->isAllowed($loc)) {
                    $skipped++;

                    continue;
                }

                $subSitemaps++;

                try {
                    $sub = simplexml_load_string(Http::timeout($timeout)->get($loc)->body());

                    foreach ($sub?->url ?? [] as $u) {
                        $subLoc = trim((string) $u->loc);

                        if ($subLoc === '') {
                            continue;
                        }

                        if (! $guard->isAllowed($subLoc)) {
                            $skipped++;

                            continue;
                        }

                        $urls[] = $subLoc;

                        if (count($urls) >= $limit) {
                            break 2;
                        }
                    }
                } catch (Throwable) {
                }

                continue;
            }

            if (! $guard->isAllowed($loc)) {
                $skipped++;

                continue;
            }

            $urls[] = $loc;

            if (count($urls) >= $limit) {
                break;
            }
        }

        return ['urls' => array_values(array_unique($urls)), 'skipped' => $skipped];
    }

    /**
     * 体检单个页面。
     *
     * @return array<int, array{type: string, url: string, detail?: string}>
     */
    protected function auditPage(string $url, int $timeout, SafeUrl $guard): array
    {
        // 请求前的最后一道闸：URL 必须是被允许的站点地址
        if (! $guard->isAllowed($url)) {
            return [[
                'type' => 'request_blocked',
                'url' => $url,
                'detail' => mb_substr($guard->reason(), 0, 120),
            ]];
        }

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

    /**
     * 写报告文件并按设置清理旧报告。
     */
    protected function writeReport(string $base, int $checked, array $summary, array $issues): string
    {
        $reportPath = storage_path('app/seo-audits/audit-'.now()->format('Ymd-His').'.json');
        @mkdir(dirname($reportPath), 0755, true);

        file_put_contents($reportPath, json_encode([
            'base_url' => $base,
            'checked' => $checked,
            'summary' => $summary,
            'issues' => $issues,
            'finished_at' => now()->toIso8601String(),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        // 报告保留份数（设置面板 advanced.keep_reports，0 = 保留全部）
        $keep = (int) plugin_setting('ai-seo-auditor', 'keep_reports', 0);

        if ($keep > 0) {
            $allReports = glob(storage_path('app/seo-audits/audit-*.json')) ?: [];
            sort($allReports);
            foreach (array_slice($allReports, 0, -$keep) as $oldReport) {
                @unlink($oldReport);
            }
        }

        return $reportPath;
    }
}
