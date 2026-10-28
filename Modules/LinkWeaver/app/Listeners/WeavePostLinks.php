<?php

namespace Modules\LinkWeaver\Listeners;

use App\Events\PostPublished;
use Illuminate\Support\Facades\Log;
use Modules\LinkWeaver\Services\Weaver;
use Throwable;

/**
 * 文章发布 → 自动织入词典内链。
 * 失败只记日志不抛出：内链失败不应阻塞发布主流程（与 AutoSeo 同哲学）。
 */
class WeavePostLinks
{
    public function __construct(private readonly Weaver $weaver)
    {
    }

    public function handle(PostPublished $event): void
    {
        try {
            $this->weaver->weavePost($event->post);
        } catch (Throwable $e) {
            Log::warning('LinkWeaver: 自动织链失败', [
                'post' => $event->post->id,
                'e' => $e->getMessage(),
            ]);
        }
    }
}
