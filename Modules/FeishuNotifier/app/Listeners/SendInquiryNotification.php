<?php

namespace Modules\FeishuNotifier\Listeners;

use App\Events\InquiryCreated;
use App\Models\User;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\FeishuNotifier\Support\FeishuClient;

class SendInquiryNotification implements ShouldQueue
{
    public function handle(InquiryCreated $event): void
    {
        $inquiry = $event->inquiry;

        // 设置面板开关：新询盘推送可独立关闭（插件总开关在 FeishuClient 内检查）
        if (! plugin_setting('feishu-notifier', 'notify_new_inquiry', true)) {
            return;
        }

        $lines = [
            '**询盘 #'.$inquiry->id.'**',
            '- 客户：'.$inquiry->name.($inquiry->company ? '（'.$inquiry->company.'）' : ''),
            '- 邮箱：'.$inquiry->email,
            '- 电话：'.($inquiry->phone ?: '—'),
            '- 国家：'.($inquiry->country ?: '未知'),
            '- 来源：'.$inquiry->source,
            '- 落地页：'.($inquiry->landing_url ?: '—'),
            '- 内容：'.mb_substr((string) $inquiry->message, 0, 300),
        ];

        $result = FeishuClient::sendCard(
            '📩 新询盘提醒',
            implode("\n", $lines),
            'blue',
            plugin_setting('feishu-notifier', 'admin_url', config('feishu-notifier.admin_url')),
            '打开后台处理',
        );

        // 推送失败（网络/SSL/飞书报错）→ 站内铃铛告警管理员，避免"没提示"静默丢失
        if ($result['status'] === 'failed') {
            Notification::make()
                ->title('飞书推送失败')
                ->body('询盘 #'.$inquiry->id.'（'.$inquiry->email.'）未送达飞书群：'.$result['error'])
                ->danger()
                ->persistent()
                ->sendToDatabase(User::query()->get());
        }
    }
}
