<?php

namespace App\Filament\Widgets;

use App\Services\AppUrlHealth;
use Filament\Widgets\Widget;

/**
 * APP_URL 一致性告警（v1.4.19）：宝塔开启 HTTPS 后最常见的翻车是
 * APP_URL 还是 http:// —— 全站链接仍按 http 生成，浏览器把 CSS/JS
 * 当混合内容拦截，整站样式全丢。此 widget 在不一致时置顶提醒，
 * 一键同步（纯 HTML 表单提交，不依赖 JS——此时 Livewire 脚本本身
 * 也可能被拦截）。一致或非管理员时不渲染，零噪音。
 */
class AppUrlStatusWidget extends Widget
{
    protected string $view = 'filament.widgets.app-url-status';

    protected array|string|int $columnSpan = 'full';

    /** 置于「新手开始清单」（-4）之上：环境不对，先修环境 */
    protected static ?int $sort = -5;

    public static function canView(): bool
    {
        // .env 级配置只有管理员能改，编辑不可见不可点
        return user_is_admin() && ! AppUrlHealth::inspect()['ok'];
    }

    /**
     * @return array<string, mixed>
     */
    public function getViewData(): array
    {
        return [
            'health' => AppUrlHealth::inspect(),
            // 同步失败（.env 不可写等）时回跳到本页，错误信息在这里展示
            'flash' => session('app_url_sync'),
        ];
    }
}
