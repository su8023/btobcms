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
            // 只缓存标量数组（缓存模型对象跨进程反序列化不可靠）；未命中不缓存，新增规则立即生效
            $cached = Cache::get("redirect:{$path}");

            if (is_array($cached)) {
                Redirect::query()->where('from_path', $path)->increment('hits');

                return redirect($cached['to'], $cached['code']);
            }

            $redirect = Redirect::query()->where('from_path', $path)->first(['to_path', 'code']);

            if ($redirect !== null) {
                Cache::put("redirect:{$path}", ['to' => $redirect->to_path, 'code' => $redirect->code], now()->addHour());
                $redirect->increment('hits');

                return redirect($redirect->to_path, $redirect->code);
            }
        } catch (\Throwable) {
            // 表未迁移等异常时放行，不阻塞请求
            return $next($request);
        }

        return $next($request);
    }
}
