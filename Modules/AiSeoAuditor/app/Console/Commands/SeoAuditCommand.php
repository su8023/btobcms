<?php

namespace Modules\AiSeoAuditor\Console\Commands;

use Illuminate\Console\Command;
use Modules\AiSeoAuditor\Services\AuditRunner;

class SeoAuditCommand extends Command
{
    protected $signature = 'seo:audit
        {--base-url= : 站点根地址（默认：插件中心设置 → APP_URL）}
        {--limit= : 最多检查页面数（默认：插件中心设置 → 100）}
        {--timeout=10 : 单页请求超时秒数}';

    protected $description = '抓取站点 sitemap 并做规则化 SEO 巡检（TDK/H1/canonical/状态码）';

    public function handle(AuditRunner $runner): int
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

        $result = $runner->run($base, $limit, $timeout);

        $summary = $result['summary'];
        $issues = [];

        // 明细从报告文件回读（避免把全部 issue 留在内存里）
        $report = json_decode((string) @file_get_contents($result['report_path']), true);
        $issues = is_array($report['issues'] ?? null) ? $report['issues'] : [];

        $this->info('开始巡检 '.$result['checked'].' 个页面：'.$base);

        if ($result['skipped'] > 0) {
            $this->warn("已跳过 {$result['skipped']} 个不在允许范围内的地址（非本站主机 / 指向内网）。");
        }

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

        $this->info('报告已保存：'.$result['report_path']);

        return self::SUCCESS;
    }
}
