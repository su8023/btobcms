<?php

namespace Modules\MailNotifier\Settings;

class PluginSettings
{
    public static function configPrefix(): string
    {
        return 'mail-notifier';
    }

    public static function fields(): array
    {
        return [
            // ---- 功能开关 ----
            ['key' => 'enabled', 'label' => 'Enabled', 'type' => 'boolean', 'section' => 'toggles'],
            ['key' => 'send_buyer_confirmation', 'label' => 'Send buyer confirmation email', 'type' => 'boolean', 'section' => 'toggles'],
            ['key' => 'send_admin_notification', 'label' => 'Send admin notification email', 'type' => 'boolean', 'section' => 'toggles'],

            // ---- 收件人与发件人 ----
            ['key' => 'admin_emails', 'label' => 'Admin emails (comma separated)', 'type' => 'text', 'required' => true,
                'placeholder' => 'sales@example.com, boss@example.com', 'section' => 'recipients'],
            ['key' => 'cc_emails', 'label' => 'CC recipients (comma separated)', 'type' => 'text',
                'placeholder' => 'archive@example.com', 'section' => 'recipients'],
            ['key' => 'reply_to', 'label' => 'Buyer reply-to address (default: first admin email)', 'type' => 'text',
                'section' => 'recipients'],
            ['key' => 'sender_name', 'label' => 'Sender name', 'type' => 'text', 'section' => 'recipients'],

            // ---- 邮件主题（空 = 默认文案）----
            ['key' => 'buyer_subject', 'label' => 'Buyer confirmation subject ({site} placeholder)', 'type' => 'text',
                'placeholder' => 'We have received your inquiry — {site}', 'section' => 'subjects'],
            ['key' => 'admin_subject', 'label' => 'Admin notification subject ({id}/{name}/{email}/{site})', 'type' => 'text',
                'placeholder' => 'New Inquiry #{id} from {name} — {site}', 'section' => 'subjects'],
        ];
    }
}
