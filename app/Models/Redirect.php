<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Redirect extends Model
{
    protected $fillable = ['site_id', 'from_path', 'to_path', 'code'];

    protected function casts(): array
    {
        return [
            'code' => 'integer',
        ];
    }

    /**
     * 规则变更时失效缓存。
     *
     * WHY：RedirectRequests 中间件把命中结果缓存 1 小时。后台改了目标路径或
     * 删了规则，线上最长 1 小时仍跳旧地址（实测：改完目标仍跳原路径）。
     * 运营改 301 的预期是「立刻生效」。
     */
    protected static function booted(): void
    {
        static::saved(function (self $redirect): void {
            self::forgetCache($redirect->from_path);

            // from_path 被改过：旧路径的缓存也要清（否则旧路径继续跳）
            $original = $redirect->getOriginal('from_path');

            if (is_string($original) && $original !== $redirect->from_path) {
                self::forgetCache($original);
            }
        });

        static::deleted(function (self $redirect): void {
            self::forgetCache($redirect->from_path);
        });
    }

    /**
     * 清除某路径的 301 缓存（正缓存与负缓存都是同一 key）。
     */
    public static function forgetCache(string $path): void
    {
        if ($path === '') {
            return;
        }

        try {
            Cache::forget(self::cacheKey('/'.ltrim($path, '/')));
        } catch (\Throwable) {
            // 缓存驱动异常不影响规则本身的保存
        }
    }

    /**
     * 301 规则的缓存键。
     *
     * 用 sha1 而不是原始路径：路径长度不可控（扫描器会构造超长 URL），
     * 而 database 缓存驱动的 key 列只有 255 字符，超长键会直接报错。
     */
    public static function cacheKey(string $path): string
    {
        return 'redirect:'.sha1($path);
    }
}
