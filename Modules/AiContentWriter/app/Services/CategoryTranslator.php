<?php

namespace Modules\AiContentWriter\Services;

/**
 * 分类一键翻译：字段映射 name/description（无摘要）。引擎在 RecordTranslator。
 * 正文可空——分类常常只填名称；此时只翻译 name + SEO。
 */
class CategoryTranslator extends RecordTranslator
{
    protected function config(): array
    {
        return [
            'title_field' => 'name',
            'excerpt_field' => null,
            'body_field' => 'description',
            'label' => '分类',
            'require_body' => false,
        ];
    }

    protected function purposePrefix(): string
    {
        return 'category.translate';
    }
}
