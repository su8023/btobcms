<?php

namespace Modules\AiSeoAuditor\Settings;

class PluginSettings
{
    public static function configPrefix(): string
    {
        return 'ai-seo-auditor';
    }

    public static function fields(): array
    {
        return [
            // ---- 定时巡检 ----
            ['key' => 'enabled', 'label' => 'Daily scheduled audit', 'type' => 'boolean', 'section' => 'schedule'],
            ['key' => 'schedule_hour', 'label' => 'Daily audit hour (0-23, server time)', 'type' => 'number', 'section' => 'schedule'],
            ['key' => 'base_url', 'label' => 'Site base URL (for scheduled audit)', 'type' => 'text',
                'placeholder' => 'https://example.com', 'section' => 'schedule'],
            ['key' => 'limit', 'label' => 'Max pages per audit', 'type' => 'number', 'section' => 'schedule'],

            // ---- 高级 ----
            ['key' => 'audit_timeout', 'label' => 'Per-page request timeout (seconds)', 'type' => 'number', 'section' => 'advanced'],
            ['key' => 'keep_reports', 'label' => 'Report files to keep (0 = keep all)', 'type' => 'number', 'section' => 'advanced'],
        ];
    }
}
