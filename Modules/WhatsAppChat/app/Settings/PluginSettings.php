<?php

namespace Modules\WhatsAppChat\Settings;

class PluginSettings
{
    public static function configPrefix(): string
    {
        return 'whatsapp-chat';
    }

    public static function fields(): array
    {
        return [
            // ---- 基础 ----
            ['key' => 'enabled', 'label' => 'Enabled', 'type' => 'boolean', 'section' => 'basics'],
            ['key' => 'phone', 'label' => 'WhatsApp number (digits only, with country code)', 'type' => 'text', 'required' => true,
                'placeholder' => '8613800138000', 'section' => 'basics'],

            // ---- 展示与话术 ----
            ['key' => 'message', 'label' => 'Prefilled first message ({page} = current page URL)', 'type' => 'textarea',
                'section' => 'display'],
            ['key' => 'bubble', 'label' => 'Hover bubble text', 'type' => 'text', 'section' => 'display'],
            ['key' => 'delay_seconds', 'label' => 'Show delay in seconds (0 = immediately)', 'type' => 'number', 'section' => 'display'],
        ];
    }
}
