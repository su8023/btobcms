<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;

/**
 * 每请求把后台「站点设置」的联系方式合并进 config('site.contact.*')。
 *
 * 为什么是中间件而不是 Provider boot：全站 8+ 处视图/邮件直接读
 * config('site.contact.*')（首页 CTA/行业页/服务页/邮件署名等），
 * boot 阶段合并虽然覆盖 FPM 每请求重启，但保存设置后的当前进程
 * （Livewire/测试/长驻 worker）不会重跑 boot——中间件保证 HTTP 侧
 * 永远读到最新值；boot 合并仍保留，覆盖队列/CLI 场景。
 */
class ApplySiteSettings
{
    public function handle(Request $request, Closure $next): mixed
    {
        try {
            foreach (['email', 'phone', 'address'] as $field) {
                $value = Setting::get("site.contact.{$field}");

                if (filled($value)) {
                    config(["site.contact.{$field}" => is_string($value) ? $value : (string) $value]);
                }
            }
        } catch (\Throwable) {
            // 表未迁移（全新安装阶段）时跳过：走 env 默认值
        }

        return $next($request);
    }
}
