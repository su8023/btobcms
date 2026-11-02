<?php

namespace Modules\AiContentWriter\Services;

/**
 * 产品一键翻译：字段映射 name/summary/description。引擎在 RecordTranslator。
 * 正文可空（很多产品只填名称+摘要）——短字段照样值得翻译。
 */
class ProductTranslator extends RecordTranslator
{
    protected function config(): array
    {
        return [
            'title_field' => 'name',
            'excerpt_field' => 'summary',
            'body_field' => 'description',
            'label' => '产品',
            'require_body' => false,
        ];
    }

    protected function purposePrefix(): string
    {
        return 'product.translate';
    }
}
