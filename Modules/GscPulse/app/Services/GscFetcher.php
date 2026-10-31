<?php

namespace Modules\GscPulse\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\GscPulse\Models\GscCredential;
use Modules\GscPulse\Models\GscDaily;
use RuntimeException;

/**
 * Google Search Console 数据拉取器（OAuth 与 API 全用手写 Http 客户端，无 SDK）。
 *
 * 流程：refresh_token 换新 access_token（过期才刷，回存库）→
 *       分页调用 searchAnalytics/query（page/query/country 维度）→
 *       按 fingerprint 批量 upsert 写入 gsc_daily（天然幂等，重复拉不产生重复行）。
 *
 * 并发：手动「立即拉取」与每日 06:10 定时任务共用一把缓存锁（Cache::lock），
 * 抢不到锁直接退出，避免两条链路同时写同一批 fingerprint 撞唯一键。
 */
class GscFetcher
{
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const API_BASE = 'https://searchconsole.googleapis.com/webmasters/v3/sites/';

    /** 拉取互斥锁键：手动拉取与定时任务共用 */
    private const LOCK_KEY = 'gsc-pulse:fetch-lock';

    /** upsert 时需要覆盖的列（fingerprint 为唯一键，不在更新列里） */
    private const UPSERT_COLUMNS = ['site_url', 'date', 'page', 'query', 'country', 'clicks', 'impressions', 'position'];

    /**
     * 拉取一个站点属性近 N 天数据，返回本次写入（新增或更新）的行数。
     *
     * @throws RuntimeException 未连接凭据 / 刷新失败 / GSC 接口报错 / 已有拉取在跑时抛出
     */
    public function fetch(?string $siteUrl = null): int
    {
        $credential = GscCredential::current($siteUrl);

        if ($credential === null || ($credential->refresh_token ?? '') === '') {
            throw new RuntimeException('尚未连接 Google Search Console，请先在「GSC 连接」页完成授权。');
        }

        $siteUrl = $siteUrl ?? $credential->site_url;

        // 互斥锁：手动拉取与定时任务可能撞在一起（都先查后写、会撞 fingerprint 唯一键），
        // 用缓存锁把并发入口收敛成一条链路。TTL 取配置（默认 15 分钟，足够跑完一轮 5 万行）。
        $lock = Cache::lock(self::LOCK_KEY, (int) config('gsc-pulse.lock_ttl', 900));

        if (! $lock->get()) {
            throw new RuntimeException('已有 GSC 拉取任务在运行中（手动拉取与每日定时任务互斥），本次已跳过。');
        }

        try {
            // access_token 缺失或临近过期（含缓冲秒数）→ 用 refresh_token 换新并回存
            $this->ensureFreshAccessToken($credential);

            return $this->writeRows($siteUrl, (string) $credential->access_token);
        } finally {
            $lock->release();
        }
    }

    /**
     * 当前是否有拉取任务在跑（供页面提示用，只读不占锁）。
     */
    public static function isRunning(): bool
    {
        return Cache::lock(self::LOCK_KEY, (int) config('gsc-pulse.lock_ttl', 900))->isLocked();
    }

    /**
     * 拉取并落库：逐行转成 upsert 载荷，攒够一批就用一条 SQL 批量写入。
     *
     * WHY 批量：按行 updateOrCreate 每行要 SELECT + INSERT/UPDATE 两条语句，
     * 5 万行就是 10 万条语句；批量 upsert 按 fingerprint 命中唯一索引，
     * 一条语句搞定一批，且重复拉取不会撞唯一键。
     */
    protected function writeRows(string $siteUrl, string $accessToken): int
    {
        $batchSize = max(1, (int) config('gsc-pulse.upsert_batch_size', 500));
        $written = 0;
        $batch = [];

        foreach ($this->fetchAllRows($siteUrl, $accessToken) as $row) {
            $payload = $this->toUpsertRow($siteUrl, $row);

            if ($payload === null) {
                continue; // 缺关键字段（理论上不会），跳过避免脏数据
            }

            $batch[] = $payload;
            $written++;

            if (count($batch) >= $batchSize) {
                $this->flushBatch($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $this->flushBatch($batch);
        }

        return $written;
    }

    /**
     * 一批数据落库：整批一个事务。
     *
     * WHY 事务按批而非整轮：整轮事务会把 GSC 的多次 HTTP 往返全包在事务里，
     * 长事务会锁住 SQLite / MySQL 的写通道；按批提交既能保证「批内不留半截数据」，
     * 又能在中途失败时靠下一轮重跑补齐（upsert 幂等）。
     */
    protected function flushBatch(array $batch): void
    {
        DB::transaction(function () use ($batch): void {
            GscDaily::upsert($batch, ['fingerprint'], self::UPSERT_COLUMNS);
        });
    }

    /**
     * 单行转 upsert 载荷：fingerprint（五元组 md5）为唯一键，命中即更新指标——重复拉取零重复。
     *
     * @return array<string, mixed>|null 缺关键字段时返回 null（跳过该行）
     */
    protected function toUpsertRow(string $siteUrl, array $row): ?array
    {
        $keys = (array) ($row['keys'] ?? []);
        $date = (string) ($keys[0] ?? $row['date'] ?? '');
        $page = (string) ($keys[1] ?? '');
        $query = (string) ($keys[2] ?? '');
        $country = isset($keys[3]) ? (string) $keys[3] : null;

        if ($date === '' || $page === '' || $query === '') {
            return null;
        }

        return [
            'site_url' => $siteUrl,
            // upsert 不走 Eloquent 的 cast，这里手动补成与 date cast 相同的 'Y-m-d H:i:s' 文本，
            // 保证新老行格式一致（看板窗口按文本比较，格式不一致会漏行）。
            // fingerprint 仍用 GSC 返回的原始日期串，语义与历史完全一致。
            'date' => $this->normalizeDate($date),
            'page' => $page,
            'query' => $query,
            'country' => $country,
            'clicks' => (int) ($row['clicks'] ?? 0),
            'impressions' => (int) ($row['impressions'] ?? 0),
            'position' => isset($row['position']) ? round((float) $row['position'], 2) : null,
            'fingerprint' => GscDaily::makeFingerprint($siteUrl, $date, $page, $query, $country),
        ];
    }

    /**
     * 把 GSC 返回的日期串归一化为 'Y-m-d H:i:s'（与 Eloquent date cast 的落库格式一致）。
     */
    protected function normalizeDate(string $date): string
    {
        try {
            return Carbon::parse($date)->startOfDay()->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return $date; // 解析不了就原样写入，不因一行脏数据打断整轮拉取
        }
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
            'client_secret' => \Modules\GscPulse\Support\OAuthClient::clientSecret(),
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
