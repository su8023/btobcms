<?php

namespace Modules\MailNotifier\Mail;

use App\Models\Inquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * 买家确认邮件：告知询盘已收到、24 小时内回复，
 * 附站点联系方式与落地页回链，Reply-To 指向第一个管理员邮箱。
 */
class InquiryConfirmation extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Inquiry $inquiry)
    {
    }

    public function build(): self
    {
        $adminEmails = array_filter(array_map('trim', explode(',', (string) plugin_setting('mail-notifier', 'admin_emails', ''))));
        $replyTo = trim((string) plugin_setting('mail-notifier', 'reply_to', '')) ?: ($adminEmails[0] ?? null);

        // 主题模板（设置面板可覆盖）：占位符 {site}
        $subject = trim((string) plugin_setting('mail-notifier', 'buyer_subject', ''));
        $subject = strtr($subject !== '' ? $subject : 'We have received your inquiry — {site}', ['{site}' => (string) config('site.name')]);

        $this->from(config('mail.from.address'), plugin_setting('mail-notifier', 'sender_name'))
            ->subject($subject)
            ->view('mailnotifier::emails.buyer-confirmation', [
                'siteName' => (string) config('site.name'),
                'contactEmail' => (string) config('site.contact.email'),
                'contactPhone' => (string) config('site.contact.phone'),
                'landingUrl' => (string) ($this->inquiry->landing_url ?: ''),
                'products' => $this->inquiry->items->pluck('product.name')->filter()->values(),
            ]);

        // 买家可直接回复到管理员邮箱（或设置面板指定的回复地址）
        if ($replyTo !== null && $replyTo !== '') {
            $this->replyTo($replyTo);
        }

        return $this;
    }
}
