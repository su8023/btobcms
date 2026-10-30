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

        // 生产部署在 Cloudflare/负载均衡之后：信任代理头（X-Forwarded-For），
        // 询盘记录的才是买家真实 IP 而非 CDN 节点 IP。
        // 注意：at:'*' 意味着客户端可伪造 XFF——若站点不经代理直接暴露，请改回默认。
        $middleware->trustProxies(at: '*');

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
