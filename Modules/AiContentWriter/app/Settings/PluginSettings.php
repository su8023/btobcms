<?php

namespace Modules\AiContentWriter\Settings;

class PluginSettings
{
    public static function configPrefix(): string
    {
        return 'ai-content-writer';
    }

    public static function fields(): array
    {
        return [
            // ---- 内容生成 ----
            ['key' => 'default_lang', 'label' => 'Default article language', 'type' => 'select',
                'options' => ['en' => 'English', 'es' => 'Español', 'ar' => 'العربية', 'ru' => 'Русский'],
                'section' => 'content'],
            ['key' => 'default_words', 'label' => 'Default target words', 'type' => 'number', 'section' => 'content'],
            ['key' => 'tone', 'label' => 'Writing tone', 'type' => 'select',
                'options' => [
                    'professional' => 'Professional & trustworthy',
                    'consultative' => 'Consultative & advisory',
                    'concise' => 'Concise & direct',
                ], 'section' => 'content'],
            ['key' => 'faq_count', 'label' => 'FAQ items per article (0 = none)', 'type' => 'number', 'section' => 'content'],

            // ---- 高级 ----
            ['key' => 'target_audience', 'label' => 'Target audience hint (injected into prompt)', 'type' => 'textarea',
                'placeholder' => 'e.g. European HVAC wholesalers, US construction procurement managers', 'section' => 'advanced'],
            ['key' => 'internal_links', 'label' => 'Insert product-link placeholders in generated articles', 'type' => 'boolean',
                'section' => 'advanced'],
        ];
    }
}
