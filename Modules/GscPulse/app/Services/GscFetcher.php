<?php

namespace Modules\GscPulse\Services;

use Illuminate\Support\Facades\Http;
use Modules\GscPulse\Models\GscCredential;
use Modules\GscPulse\Models\GscDaily;
use RuntimeException;

/**
 * Google Search Console 数据拉取器（OAuth 与 API 全用手写 Http 客户端，无 SDK）。
 *
 * 流程：refresh_token 换新 access_token（过期才刷，回存库）→
 *       分页调用 searchAnalytics/query（page/query/country 维度）→
 *       按 fingerprint updateOrCreate 写入 gsc_daily（天然幂等，重复拉不产生重复行）。
 */
class GscFetcher
{
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const API_BASE = 'https://searchconsole.googleapis.com/webmasters/v3/sites/';

    /**
     * 拉取一个站点属性近 N 天数据，返回本次写入（新增或更新）的行数。
     *
     * @throws RuntimeException 未连接凭据 / 刷新失败 / GSC 接口报错时抛出
     */
    public function fetch(?string $siteUrl = null): int
    {
        $credential = GscCredential::current($siteUrl);

        if ($credential === null || ($credential->refresh_token ?? '') === '') {
            throw new RuntimeException('尚未连接 Google Search Console，请先在「GSC 连接」页完成授权。');
        }

        $siteUrl = $siteUrl ?? $credential->site_url;

        // access_token 缺失或临近过期（含缓冲秒数）→ 用 refresh_token 换新并回存
        $this->ensureFreshAccessToken($credential);

        $upserted = 0;

        foreach ($this->fetchAllRows($siteUrl, $credential->access_token) as $row) {
            $this->upsertRow($siteUrl, $row);
            $upserted++;
        }

        return $upserted;
    }

    /**
     * 保证凭据上的 access_token 可用：过期（或临近过期）时刷新并回存。
     */
    protected function ensureFreshAccessToken(GscCredential $credential): void
    {
        $buffer = (int) config('gsc-pulse.token_refresh_buffer', 60);

        if (($credential->access_token ?? '') !== '' && $credential->token_expires_at !== null
            && $credential->token_expires_at->gt(now()->addSeconds($buffer))) {
            return; // 仍有效，无需刷新
        }

        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => (string) plugin_setting('gsc-pulse', 'client_id', ''),
            'client_secret' => (string) plugin_setting('gsc-pulse', 'client_secret', ''),
            'refresh_token' => (string) $credential->refresh_token,
            'grant_type' => 'refresh_token',
        ]);

        if ($response->failed() || ! $response->json('access_token')) {
            throw new RuntimeException('刷新 access_token 失败：'.$this->shortError($response));
        }

        $credential->forceFill([
            'access_token' => (string) $response->json('access_token'),
            'token_expires_at' => now()->addSeconds(max(60, (int) $response->json('expires_in', 3600))),
        ])->save();
    }

    /**
     * 分页拉取整个窗口的原始行（生成器，边拉边产出）。
     * 每页 startRow 前进；返回行数不足 row_limit 或达到安全页数上限即停止。
     *
     * @return \Generator<int, array<string, mixed>>
     */
    protected function fetchAllRows(string $siteUrl, string $accessToken): \Generator
    {
        $rowLimit = (int) config('gsc-pulse.row_limit', 2500);
        $maxPages = (int) config('gsc-pulse.max_pages', 20);
        $startDate = now()->subDays((int) config('gsc-pulse.days_back', 28))->toDateString();
        $endDate = now()->subDay()->toDateString();
        // GSC 属性 URL 含协议与斜杠，必须整体 rawurlencode 进路径
        $endpoint = self::API_BASE.rawurlencode($siteUrl).'/searchAnalytics/query';

        $startRow = 0;

        for ($page = 0; $page < $maxPages; $page++) {
            $response = Http::withToken($accessToken)->post($endpoint, [
                'startDate' => $startDate,
                'endDate' => $endDate,
                // 维度顺序决定返回行 keys 的下标：0=date 1=page 2=query 3=country。
                // 必须带 date 维度：不带时 GSC 返回整个窗口的聚合行（无逐日明细），
                // 而 gsc_daily 按天存储、衰退预警按 date 分组环比都依赖逐日数据。
                'dimensions' => ['date', 'page', 'query', 'country'],
                'rowLimit' => $rowLimit,
                'startRow' => $startRow,
            ]);

            if ($response->failed()) {
                throw new RuntimeException('GSC searchAnalytics 请求失败：'.$this->shortError($response));
            }

            $rows = (array) $response->json('rows', []);

            foreach ($rows as $row) {
                yield $row;
            }

            // 不满一页说明已到末页；满足则翻页继续
            if (count($rows) < $rowLimit) {
                break;
            }

            $startRow += count($rows);
        }
    }

    /**
     * 单行写入：fingerprint（五元组 md5）为唯一键，命中即更新指标——重复拉取零重复。
     */
    protected function upsertRow(string $siteUrl, array $row): void
    {
        $keys = (array) ($row['keys'] ?? []);
        $date = (string) ($keys[0] ?? $row['date'] ?? '');
        $page = (string) ($keys[1] ?? '');
        $query = (string) ($keys[2] ?? '');
        $country = isset($keys[3]) ? (string) $keys[3] : null;

        if ($date === '' || $page === '' || $query === '') {
            return; // 缺关键字段（理论上不会），跳过避免脏数据
        }

        GscDaily::updateOrCreate(
            ['fingerprint' => GscDaily::makeFingerprint($siteUrl, $date, $page, $query, $country)],
            [
                'site_url' => $siteUrl,
                'date' => $date,
                'page' => $page,
                'query' => $query,
                'country' => $country,
                'clicks' => (int) ($row['clicks'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
                'position' => isset($row['position']) ? round((float) $row['position'], 2) : null,
            ],
        );
    }

    /**
     * 截取上游错误信息用于通知/日志，避免整段 HTML 响应刷屏。
     */
    protected function shortError($response): string
    {
        $message = (string) ($response->json('error.message')
            ?? $response->json('error_description')
            ?? $response->body());

        return mb_substr(trim($message), 0, 160);
    }
}
