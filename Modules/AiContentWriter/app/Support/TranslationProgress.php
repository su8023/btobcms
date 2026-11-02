<?php

namespace Modules\AiContentWriter\Support;

use Illuminate\Support\Facades\Cache;

/**
 * AI 翻译任务的实时进度（按「实体类型+ID」聚合，供翻译覆盖率看板与列表页轮询展示）。
 *
 * 状态机：queued（已派发待消化）→ running（worker 执行中）→ done / failed。
 * 每个 (type, id, locale) 一条记录，写入带短锁防并发覆盖；done/failed 保留 30 分钟
 * 供回看后自动消失，queued/running 兜底 2 小时（worker 挂掉不至于永久显示进行中）。
 *
 * WHY 带 type：文章、产品、分类的主键各自独立，id=5 可能同时是某篇文章和某个产品，
 * 缓存键与索引必须按类型隔离，否则进度互相覆盖。$type 默认 'post' 且放在参数末位，
 * 既有文章侧调用零改动。
 */
class TranslationProgress
{
    public const QUEUED = 'queued';
    public const RUNNING = 'running';
    public const DONE = 'done';
    public const FAILED = 'failed';

    /** 近期有进度记录的实体索引键（供看板/列表页列出进度区） */
    private const INDEX_KEY = 'translation-progress:index';

    /**
     * 记录某实体某语言的最新状态。
     */
    public static function mark(int $id, string $locale, string $state, string $note = '', string $type = 'post'): void
    {
        $key = self::key($type, $id);

        // 短锁合并写入：同一实体多语言任务可能先后更新同一份 JSON
        Cache::lock($key.':lk', 5)->block(2, function () use ($key, $type, $id, $locale, $state, $note): void {
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

            // 维护「近期有进度记录的实体」索引（成员键 "type:id"），供页面列出进度区（2 小时滚动窗口）
            $index = Cache::get(self::INDEX_KEY);
            $index = is_array($index) ? $index : [];
            $index["{$type}:{$id}"] = now()->timestamp;
            // 只留最近 2 小时内有活动的实体，防无界增长
            $index = array_filter($index, fn (int $ts): bool => $ts > now()->subHours(2)->timestamp);
            Cache::put(self::INDEX_KEY, $index, now()->addHours(2));
        });
    }

    /**
     * 有进度记录的实体（type+id），按最近更新排序。
     *
     * @return list<array{type: string, id: int}>
     */
    public static function trackedEntities(): array
    {
        $index = Cache::get(self::INDEX_KEY);

        if (! is_array($index) || $index === []) {
            return [];
        }

        arsort($index);

        $out = [];

        foreach (array_keys($index) as $member) {
            // 成员必须为 "type:id"；升级前写入的旧格式（纯 id）或损坏成员跳过，
            // 等 2 小时 TTL 自然淘汰，避免解构告警
            $parts = explode(':', (string) $member, 2);

            if (count($parts) !== 2 || $parts[0] === '' || ! ctype_digit($parts[1])) {
                continue;
            }

            $out[] = ['type' => $parts[0], 'id' => (int) $parts[1]];
        }

        return $out;
    }

    /**
     * 近期有进度记录的「文章」ID（按最近更新排序）。
     * 兼容旧调用：只返回 type=post 的实体 id。
     *
     * @return list<int>
     */
    public static function trackedPosts(): array
    {
        return array_values(array_map(
            fn (array $e): int => $e['id'],
            array_filter(self::trackedEntities(), fn (array $e): bool => $e['type'] === 'post'),
        ));
    }

    /**
     * 某实体的进度条目（locale 代码原样返回，标签由页面映射）。
     * 排序：进行中 → 排队中 → 失败 → 已完成（把用户最关心的放前面）。
     *
     * @return list<array{locale: string, state: string, at: string, note: string}>
     */
    public static function forEntity(string $type, int $id): array
    {
        $entries = self::read(self::key($type, $id));

        $order = [self::RUNNING => 0, self::QUEUED => 1, self::FAILED => 2, self::DONE => 3];
        uasort($entries, fn (array $a, array $b): int => ($order[$a['state']] ?? 9) <=> ($order[$b['state']] ?? 9));

        return collect($entries)
            ->map(fn (array $meta, string $locale): array => ['locale' => $locale] + $meta)
            ->values()
            ->all();
    }

    /** 某文章的进度条目（type=post 便捷入口） */
    public static function forPost(int $id): array
    {
        return self::forEntity('post', $id);
    }

    /** 是否仍有在途任务（排队/进行中）——页面据此决定要不要继续轮询 */
    public static function hasInFlight(int $id, string $type = 'post'): bool
    {
        foreach (self::read(self::key($type, $id)) as $meta) {
            if (($meta['state'] ?? '') === self::QUEUED || ($meta['state'] ?? '') === self::RUNNING) {
                return true;
            }
        }

        return false;
    }

    public static function clear(int $id, string $type = 'post'): void
    {
        Cache::forget(self::key($type, $id));

        // 同步摘除实体索引，避免进度区列出一张空卡片
        $index = Cache::get(self::INDEX_KEY);

        if (is_array($index) && array_key_exists("{$type}:{$id}", $index)) {
            unset($index["{$type}:{$id}"]);
            Cache::put(self::INDEX_KEY, $index, now()->addHours(2));
        }
    }

    private static function key(string $type, int $id): string
    {
        return 'translation-progress:'.$type.':'.$id;
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
