<?php

namespace Modules\FeishuNotifier\Settings;

/**
 * 插件中心设置面板的字段声明（插件中心卡片链入独立设置页 /admin/plugins/{Name}/settings）。
 */
class PluginSettings
{
    public static function configPrefix(): string
    {
        return 'feishu-notifier';
    }

    public static function fields(): array
    {
        return [
            // ---- 推送开关 ----
            ['key' => 'enabled', 'label' => 'Enabled', 'type' => 'boolean', 'section' => 'toggles'],
            ['key' => 'notify_new_inquiry', 'label' => 'Push new inquiries', 'type' => 'boolean', 'section' => 'toggles'],
            ['key' => 'notify_audit_report', 'label' => 'Push SEO audit reports', 'type' => 'boolean', 'section' => 'toggles'],

            // ---- 飞书接入 ----
            ['key' => 'webhook', 'label' => 'Feishu Webhook URL', 'type' => 'text', 'required' => true,
                'placeholder' => 'https://open.feishu.cn/open-apis/bot/v2/hook/xxxx', 'section' => 'integration'],
            ['key' => 'secret', 'label' => 'Sign secret (optional)', 'type' => 'password', 'section' => 'integration'],

            // ---- 卡片跳转链接 ----
            ['key' => 'admin_url', 'label' => 'Card button URL (opens admin panel)', 'type' => 'text',
                'placeholder' => 'https://your-site.com/admin', 'section' => 'display'],
        ];
    }
}
