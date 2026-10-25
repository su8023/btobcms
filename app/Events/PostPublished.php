<?php

namespace App\Events;

use App\Models\Post;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 文章发布（草稿/审核 → 已发布）。AI 写作插件的发布动作、后台手动发布都会触发。
 * 插件接入示例：自动提交索引、推送社交渠道、触发内容分发。
 */
class PostPublished
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Post $post,
    ) {
    }
}
