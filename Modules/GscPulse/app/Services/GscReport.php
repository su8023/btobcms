<?php

namespace Modules\GscPulse\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Modules\GscPulse\Models\GscCredential;
use Modules\GscPulse\Models\GscDaily;
/**
 * GSC 看板聚合：纯数据库计算，零外部调用。
 * 看板页（GscDashboardPage）与测试共用，保证筛选口径一致。
 *
 * 数据窗口：整体窗口 = 今天 - days_back ~ 昨天（GSC 最终数据延迟约 1-2 天）；
 * 衰退预警的两个 7 天窗口：近 7 天 = [D-7, D-1]，前 7 天 = [D-14, D-8]，两窗相邻不重叠。
 */
class GscReport
{
    /**
     * 概览统计：总点击 / 总展现 / 平均排名（按展现加权更准，这里取行均值口径与 GSC 一致性优先，注释说明）。
     *
     * @return array{clicks: int, impressions: int, position: ?float}
     */
    public function stats(?string $siteUrl = null): array
    {
        $row = $this->baseQuery($siteUrl)
            ->selectRaw('SUM(clicks) as clicks, SUM(impressions) as impressions, AVG(position) as position')
            ->first();

        return [
            'clicks' => (int) ($row->clicks ?? 0),
            'impressions' => (int) ($row->impressions ?? 0),
            'position' => $row->position !== null ? round((float) $row->position, 1) : null,
        ];
    }

    /**
     * 临门一脚：page × query 聚合，平均排名在 [min_position, max_position] 区间
     * 且窗口内总展现 ≥ min_impressions；按总点击升序（点击最少的排最前，最该被优先补强）。
     */
    public function nearMiss(?string $siteUrl = null): Collection
    {
        $min = (int) config('gsc-pulse.near_miss.min_position', 4);
        $max = (int) config('gsc-pulse.near_miss.max_position', 15);
        $minImpressions = (int) config('gsc-pulse.near_miss.min_impressions', 100);

        return $this->baseQuery($siteUrl)
            ->select('page', 'query')
            ->selectRaw('AVG(position) as avg_position, SUM(impressions) as impressions, SUM(clicks) as clicks')
            ->groupBy('page', 'query')
            // HAVING 里重复聚合表达式以兼容 SQLite（不可引用 select 别名）与 MySQL
            ->havingRaw('AVG(position) >= ? AND AVG(position) <= ?', [$min, $max])
            ->havingRaw('SUM(impressions) >= ?', [$minImpressions])
            ->orderByRaw('SUM(clicks) asc')
            ->limit((int) config('gsc-pulse.board_limit', 10))
            ->get();
    }

    /**
     * 衰退预警：按 page 聚合近 7 天点击 vs 前 7 天点击，降幅 ≥ threshold% 上榜（按降幅降序）。
     * 两窗各自独立聚合后在 PHP 内对齐，避免一次 SQL 里写复杂的条件聚合导致方言差异。
     *
     * @return \Illuminate\Support\Collection<int, object{page: string, recent_clicks: int, prev_clicks: int, drop_percent: float}>
     */
    public function declining(?string $siteUrl = null)
    {
        $threshold = (float) config('gsc-pulse.diminishing_threshold', 30);
        $minPrev = (int) config('gsc-pulse.diminishing_min_prev_clicks', 10);

        $recent = $this->clicksByPage($siteUrl, now()->subDays(7)->toDateString(), now()->subDay()->toDateString());
        $prev = $this->clicksByPage($siteUrl, now()->subDays(14)->toDateString(), now()->subDays(8)->toDateString());

        $rows = [];

        foreach ($prev as $page => $prevClicks) {
            $recentClicks = (int) ($recent[$page] ?? 0);
            $prevClicks = (int) $prevClicks;

            // 样本门槛：前窗点击太少（如个位数）不构成有效衰退信号
            if ($prevClicks < $minPrev || $recentClicks >= $prevClicks) {
                continue;
            }

            $drop = round((($prevClicks - $recentClicks) / $prevClicks) * 100, 1);

            if ($drop >= $threshold) {
                $rows[] = (object) [
                    'page' => $page,
                    'recent_clicks' => $recentClicks,
                    'prev_clicks' => $prevClicks,
                    'drop_percent' => $drop,
                ];
            }
        }

        usort($rows, fn ($a, $b) => $b->drop_percent <=> $a->drop_percent);

        return collect(array_slice($rows, 0, (int) config('gsc-pulse.board_limit', 10)));
    }

    /**
     * 机会词：query 聚合，窗口内总展现 ≥ min_impressions 且总点击 = 0，按展现降序。
     * （有曝光没点击 → 标题/摘要与搜索意图不匹配，是现成的 CTR 优化标的。）
     */
    public function opportunities(?string $siteUrl = null): Collection
    {
        $minImpressions = (int) config('gsc-pulse.opportunity.min_impressions', 50);

        return $this->baseQuery($siteUrl)
            ->select('query')
            ->selectRaw('SUM(impressions) as impressions, SUM(clicks) as clicks')
            ->groupBy('query')
            ->havingRaw('SUM(impressions) >= ? AND SUM(clicks) = ?', [$minImpressions, 0])
            ->orderByRaw('SUM(impressions) desc')
            ->limit((int) config('gsc-pulse.board_limit', 10))
            ->get();
    }

    /**
     * 分语言统计：按落地页路径前缀归语言（/es/… → es；无前缀 → 默认语言），
     * 语言清单来自站点配置 site.locales。返回 [['locale' => '', 'label' => '…', 'clicks' => n], …] 按点击降序。
     *
     * @return list<array{locale: string, label: string, clicks: int}>
     */
    public function byLanguage(?string $siteUrl = null): array
    {
        $clicks = $this->baseQuery($siteUrl)
            ->select('page')
            ->selectRaw('SUM(clicks) as clicks')
            ->groupBy('page')
            ->pluck('clicks', 'page');

        $defaultLocale = default_locale();
        $buckets = [];

        foreach ($clicks as $page => $pageClicks) {
            $locale = $this->detectLocale((string) $page);

            $buckets[$locale] = ($buckets[$locale] ?? 0) + (int) $pageClicks;
        }

        arsort($buckets);

        $rows = [];

        foreach ($buckets as $locale => $total) {
            $rows[] = [
                'locale' => $locale,
                'label' => $locale === '' || $locale === $defaultLocale
                    ? '默认语言（'.strtoupper($defaultLocale).'）'
                    : strtoupper($locale),
                'clicks' => (int) $total,
            ];
        }

        return $rows;
    }

    /**
     * 看板主窗口查询底座：固定站点属性 + days_back 天窗口。
     */
    protected function baseQuery(?string $siteUrl = null): Builder
    {
        $siteUrl ??= GscCredential::defaultSiteUrl();

        return $this->applyDateRange(
            GscDaily::query()->where('site_url', $siteUrl),
            now()->subDays((int) config('gsc-pulse.days_back', 28))->toDateString(),
            now()->subDay()->toDateString(),
        );
    }

    /**
     * 指定窗口内按 page 汇总点击：[page => clicks]。
     *
     * @return array<string, int>
     */
    protected function clicksByPage(?string $siteUrl, string $fromDate, string $toDate): array
    {
        $siteUrl ??= GscCredential::defaultSiteUrl();

        return $this->applyDateRange(
            GscDaily::query()->where('site_url', $siteUrl),
            $fromDate,
            $toDate,
        )
            ->groupBy('page')
            ->selectRaw('page, SUM(clicks) as clicks')
            ->pluck('clicks', 'page')
            ->all();
    }

    /**
     * 日期窗口过滤：统一用「当天 00:00:00 ~ 当天 23:59:59」闭区间。
     *
     * WHY：gsc_daily.date 由 Eloquent 的 date cast 写入，存的是 'Y-m-d H:i:s' 文本。
     * 直接 whereBetween('date', ['2026-10-03', '2026-10-30']) 在 SQLite 上按文本比较，
     * 末日行的 '2026-10-30 00:00:00' > '2026-10-30' 会被排除——窗口最后一天恒丢，
     * 「昨天」永远进不了任何榜单。MySQL 上 DATE 列会截断时间部分，故比较两端都补齐
     * 时间边界（DATE 列按 00:00:00 参与比较），SQLite / MySQL 命中的是同一批行。
     */
    protected function applyDateRange(Builder $query, string $fromDate, string $toDate): Builder
    {
        return $query
            ->where('date', '>=', $fromDate.' 00:00:00')
            ->where('date', '<=', $toDate.' 23:59:59');
    }

    /**
     * 从页面 URL 识别语言前缀：匹配 /{locale}/ 或 /{locale} 结尾才算命中（避免 /english 误判），
     * 默认语言与未识别路径都归入 ''（默认语言桶）。
     */
    protected function detectLocale(string $page): string
    {
        $path = parse_url($page, PHP_URL_PATH) ?: '';
        $default = default_locale();

        foreach (site_locales() as $locale) {
            if ($locale === $default) {
                continue; // 默认语言不带前缀，不需要检测
            }

            if (preg_match('#^/'.$locale.'(/|$)#i', $path) === 1) {
                return strtolower((string) $locale);
            }
        }

        return '';
    }
}
