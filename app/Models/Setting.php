<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $fillable = ['site_id', 'key', 'value'];

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }

    /** 请求内记忆化：同一 key 同一 site 一请求只查一次（每页 15+ 次调用点） */
    protected static array $memo = [];

    /** 缓存 TTL：设置变更极低频，写侧失效后立即生效 */
    protected const CACHE_TTL_SECONDS = 300;

    public static function get(string $key, mixed $default = null, ?int $siteId = null): mixed
    {
        $siteId ??= 0;
        $memoKey = "{$siteId}:{$key}";

        if (array_key_exists($memoKey, static::$memo)) {
            $value = static::$memo[$memoKey];
        } else {
            // 缓存值用 missing 哨兵区分「查过且不存在」与「未查过」
            $value = Cache::remember(
                "setting:{$siteId}:{$key}",
                now()->addSeconds(self::CACHE_TTL_SECONDS),
                function () use ($key, $siteId) {
                    $setting = static::query()
                        ->where(function ($q) use ($siteId) {
                            $q->whereNull('site_id');
                            if ($siteId) {
                                $q->orWhere('site_id', $siteId);
                            }
                        })
                        ->where('key', $key)
                        ->orderByDesc('site_id')
                        ->first();

                    return $setting !== null ? $setting->value : '__missing__';
                },
            );

            static::$memo[$memoKey] = $value;
        }

        if ($value === '__missing__') {
            return $default;
        }

        return $value;
    }

    public static function put(string $key, mixed $value, ?int $siteId = null): void
    {
        static::updateOrCreate(
            ['site_id' => $siteId, 'key' => $key],
            ['value' => $value],
        );

        // 写侧立即失效（get 侧缓存键与旧实现 settings.* 对齐清理）+ 请求内记忆化清除
        Cache::forget('setting:'.($siteId ?? 0).":{$key}");
        Cache::forget("settings.{$siteId}.{$key}");
        unset(static::$memo[($siteId ?? 0).":{$key}"]);
    }

    /** 测试与长驻进程兜底：清空请求内记忆化 */
    public static function flushMemo(): void
    {
        static::$memo = [];
    }
}
