<?php

namespace App\Http\Controllers\Admin;

use App\Services\AppUrlHealth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * APP_URL 一键同步（v1.4.19）：宝塔开启强制 HTTPS 后，无需再 SSH 手改
 * .env + config:cache——管理员点一下按钮，APP_URL 跟随当前访问地址。
 *
 * 入口是纯 HTML 表单 POST（不依赖 JS）：协议不一致的坏站点上，浏览器会
 * 把后台的 Livewire 脚本当混合内容拦掉，Filament 的按钮全部失灵——
 * 这里的普通表单提交是唯一仍可用的修复通道。
 */
class AppUrlSyncController
{
    public function __invoke(Request $request): RedirectResponse
    {
        // 与站点设置/插件中心同口径：只有管理员能改 .env 级配置
        abort_unless(user_is_admin(), 403);

        $result = AppUrlHealth::sync((string) $request->input('app_url', ''));

        // flash 到 session：前台警示条与仪表盘 widget 下一请求都会展示；
        // 成功且已一致时入口自动消失（widget/警示条不再渲染），即视觉确认
        return back()->with('app_url_sync', $result);
    }
}
