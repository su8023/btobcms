<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetAdminLocale
{
    /**
     * 后台支持的语言白名单。
     *
     * @var list<string>
     */
    public const SUPPORTED_LOCALES = ['en', 'zh_CN'];

    /**
     * Handle an incoming request.
     *
     * 优先级：用户资料 locale > 会话 admin_locale > 默认 en。
     * 仅当解析结果在白名单内时才切换 app locale；切换后刷新 scoped 实例
     * （如 Filament 的 NavigationManager），确保导航等已构建状态按新语言重建——
     * 这对测试内共享容器与未来长驻进程（Octane）都是必要的。
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->locale
            ?? $request->session()->get('admin_locale')
            ?? 'en';

        if (in_array($locale, self::SUPPORTED_LOCALES, true) && $locale !== app()->getLocale()) {
            app()->setLocale($locale);
            app()->forgetScopedInstances();
        }

        return $next($request);
    }
}
