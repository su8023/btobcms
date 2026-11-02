<?php

namespace Modules\AiContentWriter\Services;

/**
 * 文章一键翻译：字段映射 title/excerpt/content。引擎在 RecordTranslator。
 * 保留「缺正文即拒绝」的旧契约（require_body=true）。
 */
class PostTranslator extends RecordTranslator
{
    protected function config(): array
    {
        return [
            'title_field' => 'title',
            'excerpt_field' => 'excerpt',
            'body_field' => 'content',
            'label' => '文章',
            'require_body' => true,
        ];
    }

    protected function purposePrefix(): string
    {
        return 'post.translate';
    }
}
