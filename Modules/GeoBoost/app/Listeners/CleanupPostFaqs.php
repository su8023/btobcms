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
        \Modules\GeoBoost\Models\GeoFaq::where('faqable_type', Post::class)
            ->where('faqable_id', $post->id)
            ->delete();
    }
}
