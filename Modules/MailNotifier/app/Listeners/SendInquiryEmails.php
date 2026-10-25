<?php

namespace Modules\MailNotifier\Listeners;

use App\Events\InquiryCreated;
use App\Models\Inquiry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\MailNotifier\Mail\InquiryConfirmation;
use Modules\MailNotifier\Mail\InquiryNotification;
use Throwable;

/**
 * 询盘邮件闭环：买家确认邮件 + 管理员通知邮件。
 *
 * 优雅降级：插件禁用 / 收件人缺失时跳过并记录 info 日志；
 * 发送失败仅记录 warning，绝不抛出异常影响询盘主流程。
 */
class SendInquiryEmails implements ShouldQueue
{
    public function handle(InquiryCreated $event): void
    {
        $inquiry = $event->inquiry;

        $this->sendBuyerConfirmation($inquiry);
        $this->sendAdminNotification($inquiry);
    }

    protected function sendBuyerConfirmation(Inquiry $inquiry): void
    {
        try {
            if (! plugin_setting('mail-notifier', 'enabled', true)) {
                Log::info('[mail-notifier] 插件已禁用，跳过买家确认邮件。', ['inquiry_id' => $inquiry->id]);

                return;
            }

            if (! plugin_setting('mail-notifier', 'send_buyer_confirmation', true)) {
                Log::info('[mail-notifier] 买家确认邮件已关闭（设置面板），跳过。', ['inquiry_id' => $inquiry->id]);

                return;
            }

            if (blank($inquiry->email)) {
                Log::info('[mail-notifier] 询盘缺少买家邮箱，跳过买家确认邮件。', ['inquiry_id' => $inquiry->id]);

                return;
            }

            Mail::to($inquiry->email)->send(new InquiryConfirmation($inquiry));
        } catch (Throwable $e) {
            Log::warning('[mail-notifier] 买家确认邮件发送失败：'.$e->getMessage(), [
                'inquiry_id' => $inquiry->id,
                'exception' => $e,
            ]);
        }
    }

    protected function sendAdminNotification(Inquiry $inquiry): void
    {
        try {
            if (! plugin_setting('mail-notifier', 'enabled', true)) {
                Log::info('[mail-notifier] 插件已禁用，跳过管理员通知邮件。', ['inquiry_id' => $inquiry->id]);

                return;
            }

            if (! plugin_setting('mail-notifier', 'send_admin_notification', true)) {
                Log::info('[mail-notifier] 管理员通知邮件已关闭（设置面板），跳过。', ['inquiry_id' => $inquiry->id]);

                return;
            }

            $adminEmails = array_values(array_filter(array_map('trim', explode(',', (string) plugin_setting('mail-notifier', 'admin_emails', '')))));

            if (empty($adminEmails)) {
                Log::info('[mail-notifier] 未配置管理员邮箱，跳过管理员通知邮件。', ['inquiry_id' => $inquiry->id]);

                return;
            }

            $ccEmails = array_values(array_filter(array_map('trim', explode(',', (string) plugin_setting('mail-notifier', 'cc_emails', '')))));

            $mail = Mail::to($adminEmails);

            if (! empty($ccEmails)) {
                $mail->cc($ccEmails);
            }

            $mail->send(new InquiryNotification($inquiry));
        } catch (Throwable $e) {
            Log::warning('[mail-notifier] 管理员通知邮件发送失败：'.$e->getMessage(), [
                'inquiry_id' => $inquiry->id,
                'exception' => $e,
            ]);
        }
    }
}
