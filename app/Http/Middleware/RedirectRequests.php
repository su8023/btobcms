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

        try {
            // 命中缓存标量数组 → 跳转；未命中写负缓存（哨兵 0）5 分钟：
            // 匿名流量 99% 不在 301 表里，避免每个 GET 都回源查询
            $cached = Cache::get("redirect:{$path}");

            if (is_array($cached)) {
                Redirect::query()->where('from_path', $path)->increment('hits');

                return redirect($cached['to'], $cached['code']);
            }

            if ($cached === 0) {
                return $next($request);
            }

            $redirect = Redirect::query()->where('from_path', $path)->first(['to_path', 'code']);

            if ($redirect !== null) {
                Cache::put("redirect:{$path}", ['to' => $redirect->to_path, 'code' => $redirect->code], now()->addHour());
                $redirect->increment('hits');

                return redirect($redirect->to_path, $redirect->code);
            }

            Cache::put("redirect:{$path}", 0, now()->addMinutes(5));
        } catch (\Throwable) {
            // 表未迁移等异常时放行，不阻塞请求
            return $next($request);
        }

        return $next($request);
    }
}
