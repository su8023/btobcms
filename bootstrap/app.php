<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // 301 规则为全局中间件：未匹配路由的旧 URL 也要能跳转
        $middleware->prepend(\App\Http\Middleware\RedirectRequests::class);

        // 主题视图覆盖按请求应用（幂等，中途切换主题立即生效）
        $middleware->web(append: \App\Http\Middleware\ApplyTheme::class);

        // 站点设置（联系方式等）按请求合并进 config——后台改完全站立即生效
        $middleware->web(append: \App\Http\Middleware\ApplySiteSettings::class);

        // 可信反向代理（CDN / 负载均衡）不在这里配置：
        // withMiddleware 回调执行时 config 尚未加载（config() 会抛
        // "Target class [config] does not exist"），而 env() 在 config:cache
        // 之后又恒为 null。正确做法是走 Laravel 内置的 config/trustedproxy.php，
        // 由 TrustProxies 中间件自行读取 —— 既能 config:cache，语义也最标准。
        //
        // 安全提醒：TRUSTED_PROXIES=* 意味着任何客户端都能伪造 X-Forwarded-For，
        // 那样询盘 IP 会失真、基于 IP 的限流也会被直接绕过。
        // 默认留空 = 不信任任何代理（用真实连接 IP）。

        // 更新中心进入维护模式时，后台面板与 Livewire 端点必须保持可达：
        // 否则更新进程一旦被中断，管理员将无法点击「继续更新 / 恢复访问」，
        // 站点会永久停在 503，只能 SSH 救。
        $middleware->preventRequestsDuringMaintenance(except: [
            'admin',
            'admin/*',
            'livewire/*',
            'up',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
