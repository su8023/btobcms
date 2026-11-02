<?php

namespace Modules\AiContentWriter\Support;

use Illuminate\Support\Facades\Cache;

/**
 * AI 翻译任务的实时进度（按文章聚合，供翻译覆盖率看板轮询展示）。
 *
 * 状态机：queued（已派发待消化）→ running（worker 执行中）→ done / failed。
 * 每个 (post, locale) 一条记录，写入带短锁防并发覆盖；done/failed 保留 30 分钟
 * 供回看后自动消失，queued/running 兜底 2 小时（worker 挂掉不至于永久显示进行中）。
 */
class TranslationProgress
{
    public const QUEUED = 'queued';
    public const RUNNING = 'running';
    public const DONE = 'done';
    public const FAILED = 'failed';

    /** 近期有进度记录的文章索引键（供看板列出进度区） */
    private const INDEX_KEY = 'translation-progress:posts';

    /**
     * 记录某文章某语言的最新状态。
     */
    public static function mark(int $postId, string $locale, string $state, string $note = ''): void
    {
        $key = self::key($postId);

        // 短锁合并写入：同一文章多语言任务可能先后更新同一份 JSON
        Cache::lock($key.':lk', 5)->block(2, function () use ($key, $postId, $locale, $state, $note): void {
            $entries = self::read($key);
            $entries[$locale] = [
                'state' => $state,
                'at' => now()->format('H:i:s'),
                'note' => mb_substr(trim($note), 0, 200),
            ];

            $ttl = in_array($state, [self::DONE, self::FAILED], true)
                ? now()->addMinutes(30)
                : now()->addHours(2);

            Cache::put($key, $entries, $ttl);

            // 维护「近期有进度记录的文章」索引，供覆盖率页列出进度区（2 小时滚动窗口）
            $index = Cache::get(self::INDEX_KEY);
            $index = is_array($index) ? $index : [];
            $index[$postId] = now()->timestamp;
            // 只留最近 2 小时内有活动的文章，防无界增长
            $index = array_filter($index, fn (int $ts): bool => $ts > now()->subHours(2)->timestamp);
            Cache::put(self::INDEX_KEY, $index, now()->addHours(2));
        });
    }

    /**
     * 有进度记录的文章 ID（按最近更新排序）。
     *
     * @return list<int>
     */
    public static function trackedPosts(): array
    {
        $index = Cache::get(self::INDEX_KEY);

        if (! is_array($index) || $index === []) {
            return [];
        }

        arsort($index);

        return array_map('intval', array_keys($index));
    }

    /**
     * 某文章的进度条目（locale 代码原样返回，标签由页面映射）。
     * 排序：进行中 → 排队中 → 失败 → 已完成（把用户最关心的放前面）。
     *
     * @return list<array{locale: string, state: string, at: string, note: string}>
     */
    public static function forPost(int $postId): array
    {
        $entries = self::read(self::key($postId));

        $order = [self::RUNNING => 0, self::QUEUED => 1, self::FAILED => 2, self::DONE => 3];
        uasort($entries, fn (array $a, array $b): int => ($order[$a['state']] ?? 9) <=> ($order[$b['state']] ?? 9));

        return collect($entries)
            ->map(fn (array $meta, string $locale): array => ['locale' => $locale] + $meta)
            ->values()
            ->all();
    }

    /** 是否仍有在途任务（排队/进行中）——页面据此决定要不要继续轮询 */
    public static function hasInFlight(int $postId): bool
    {
        foreach (self::read(self::key($postId)) as $meta) {
            if (($meta['state'] ?? '') === self::QUEUED || ($meta['state'] ?? '') === self::RUNNING) {
                return true;
            }
        }

        return false;
    }

    public static function clear(int $postId): void
    {
        Cache::forget(self::key($postId));

        // 同步摘除文章索引，避免进度区列出一张空卡片
        $index = Cache::get(self::INDEX_KEY);

        if (is_array($index) && array_key_exists($postId, $index)) {
            unset($index[$postId]);
            Cache::put(self::INDEX_KEY, $index, now()->addHours(2));
        }
    }

    private static function key(int $postId): string
    {
        return 'translation-progress:'.$postId;
    }

    /**
     * @return array<string, array{state: string, at: string, note: string}>
     */
    private static function read(string $key): array
    {
        $value = Cache::get($key);

        return is_array($value) ? $value : [];
    }
}
