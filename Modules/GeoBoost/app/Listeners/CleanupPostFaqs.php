<?php

namespace Modules\GeoBoost\Listeners;

use App\Models\Post;

/**
 * 文章删除时清理其 FAQ 记录。
 */
class CleanupPostFaqs
{
    public function handle(Post $post): void
    {
        try {
            \Modules\GeoBoost\Models\GeoFaq::where('faqable_type', Post::class)
                ->where('faqable_id', $post->id)
                ->delete();
        } catch (\Throwable $e) {
            // 表未迁移/库异常时不阻塞文章删除主流程
            \Illuminate\Support\Facades\Log::warning('GeoBoost: 文章 FAQ 清理失败', [
                'post' => $post->id,
                'e' => $e->getMessage(),
            ]);
        }
    }
}
