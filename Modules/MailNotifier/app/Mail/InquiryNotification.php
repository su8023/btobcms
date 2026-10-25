<?php

namespace Modules\MailNotifier\Mail;

use App\Models\Inquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * 管理员通知邮件：新询盘详情
 * （客户名/邮箱/电话/公司/国家、留言全文、关联产品、UTM 来源、落地页）。
 */
class InquiryNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Inquiry $inquiry)
    {
    }

    public function build(): self
    {
        // 主题模板（设置面板可覆盖）：占位符 {id} {name} {email} {site}
        $subject = trim((string) plugin_setting('mail-notifier', 'admin_subject', ''));
        $subject = strtr(
            $subject !== '' ? $subject : 'New Inquiry #{id} from {name} — {site}',
            [
                '{id}' => (string) $this->inquiry->id,
                '{name}' => (string) $this->inquiry->name,
                '{email}' => (string) $this->inquiry->email,
                '{site}' => (string) config('site.name'),
            ],
        );

        return $this->from(config('mail.from.address'), plugin_setting('mail-notifier', 'sender_name'))
            ->subject($subject)
            ->view('mailnotifier::emails.admin-notification', [
                'siteName' => (string) config('site.name'),
                'products' => $this->inquiry->items->pluck('product.name')->filter()->values(),
                'utm' => is_array($this->inquiry->utm) ? $this->inquiry->utm : [],
            ]);
    }
}
