<?php

namespace App\Http\Middleware;

use App\Services\ThemeManager;
use Closure;
use Illuminate\Http\Request;

class ApplyTheme
{
    public function handle(Request $request, Closure $next): mixed
    {
        // 每个请求把激活主题的 views/ 前置到 View Finder（幂等）
        app(ThemeManager::class)->apply();

        return $next($request);
    }
}
