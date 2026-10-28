<?php

namespace Modules\GeoBoost\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\GeoBoost\Models\AiVisit;

/**
 * AI 引擎来访追踪：referer 命中 AI 引擎清单时记录一次落地浏览。
 * 零外部调用，单条 INSERT；不命中零开销（仅一次 parse_url）。
 */
class TrackAiReferrer
{
    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        try {
            $referer = (string) $request->header('referer', '');
            if ($referer === '' || ! $request->isMethod('GET') || $request->expectsJson()) {
                return $response;
            }

            $host = strtolower((string) parse_url($referer, PHP_URL_HOST));
            // 去掉 www. 前缀再匹配
            $host = preg_replace('/^www\./', '', $host) ?? $host;

            $engines = (array) config('geo-boost.engines', []);
            $engine = null;
            foreach ($engines as $fragment => $name) {
                if (str_contains($host, (string) $fragment)) {
                    $engine = $name;
                    break;
                }
            }

            // 自家域名上的引用不算引擎引流
            if ($engine === null || str_contains($host, (string) parse_url(config('app.url'), PHP_URL_HOST))) {
                return $response;
            }

            AiVisit::create([
                'engine' => $engine,
                'path' => mb_substr($request->path(), 0, 500),
                'referer' => mb_substr($referer, 0, 500),
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // 追踪失败绝不影响页面响应
        }

        return $response;
    }
}
