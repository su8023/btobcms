<?php

namespace App\Http\Middleware;

use App\Models\Redirect;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class RedirectRequests
{
    public function handle(Request $request, Closure $next): mixed
    {
        // 静态资源与后台不参与 301 规则
        if ($request->is('admin/*', 'admin', 'api/*', 'livewire/*', '_debugbar/*')) {
            return $next($request);
        }

        $path = '/'.ltrim($request->path(), '/');

        // 缓存键用 sha1：路径长度不可控，超长键在 database 缓存驱动下会直接报错
        $cacheKey = Redirect::cacheKey($path);

        try {
            // 命中缓存标量数组 → 跳转；未命中写负缓存（哨兵 0）5 分钟：
            // 匿名流量 99% 不在 301 表里，避免每个 GET 都回源查询
            $cached = Cache::get($cacheKey);

            if (is_array($cached)) {
                Redirect::query()->where('from_path', $path)->increment('hits');

                return redirect($cached['to'], $cached['code']);
            }

            if ($cached === 0) {
                return $next($request);
            }

            $redirect = Redirect::query()->where('from_path', $path)->first(['to_path', 'code']);

            if ($redirect !== null) {
                Cache::put($cacheKey, ['to' => $redirect->to_path, 'code' => $redirect->code], now()->addHour());
                $redirect->increment('hits');

                return redirect($redirect->to_path, $redirect->code);
            }

            // 只给"像真实内容 URL"的路径写负缓存：
            // 否则机器人扫描会为每个随机路径生成一个缓存键，
            // CACHE_STORE=database 时 cache 表会无限膨胀。
            // 真实 URL 形态是 /product/slug、/blog/slug、/category/slug 这类短路径。
            if ($this->isCacheableMissPath($path)) {
                Cache::put($cacheKey, 0, now()->addMinutes(5));
            }
        } catch (\Throwable) {
            // 表未迁移等异常时放行，不阻塞请求
            return $next($request);
        }

        return $next($request);
    }

    /**
     * 判定路径是否值得写负缓存（长度、层级、扩展名三项约束）。
     */
    protected function isCacheableMissPath(string $path): bool
    {
        if (mb_strlen($path) > 120) {
            return false;
        }

        if (substr_count($path, '/') > 4) {
            return false;
        }

        // 带文件扩展名的一律不缓存（多半是扫描器）
        if (preg_match('/\.[A-Za-z0-9]{1,6}$/', $path) === 1) {
            return false;
        }

        return true;
    }
}
