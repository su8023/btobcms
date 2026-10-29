<?php

namespace Modules\LeadMagnet\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Modules\LeadMagnet\Models\LeadMagnet;

/**
 * 资料磁铁交付邮件：正文纯文本，附 16 小时有效的签名下载链接。
 */
class MagnetLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public LeadMagnet $magnet,
        public string $url,
    ) {
    }

    public function build(): self
    {
        return $this->from(config('mail.from.address'), (string) config('site.name'))
            ->subject(__('front.mail_subject', ['title' => (string) $this->magnet->title]))
            ->text('leadmagnet::mail.magnet-link', [
                'magnet' => $this->magnet,
                'url' => $this->url,
                'siteName' => (string) config('site.name'),
            ]);
    }
}
