<?php

namespace App\Mail;

use App\Models\Inquiry;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * 询盘人工回复邮件：销售在后台直接给买家回信（纯文本）。
 *
 * 发件人使用站点发信地址（mail.from.address）；
 * Reply-To 优先取第一个管理员邮箱（mail-notifier.admin_emails），
 * 未配置则回退到站点联系邮箱（site.contact.email）。
 */
class InquiryReply extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Inquiry $inquiry,
        public string $subjectLine,
        public string $replyBody,
    ) {
    }

    public function build(): self
    {
        $adminEmails = array_values((array) config('mail-notifier.admin_emails', []));
        $replyTo = $adminEmails[0] ?? config('site.contact.email');

        $this->from(config('mail.from.address'), config('mail-notifier.sender_name'))
            ->subject($this->subjectLine)
            ->text('filament.inquiry-reply-email', [
                'siteName' => (string) config('site.name'),
                'inquiry' => $this->inquiry,
                'replyBody' => $this->replyBody,
            ]);

        if (filled($replyTo)) {
            $this->replyTo($replyTo);
        }

        return $this;
    }
}
