<?php

namespace Modules\AiContentWriter\Jobs;

use App\Models\Post;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\AiContentWriter\Services\PostTranslator;

/**
 * 单语言文章翻译任务（AI 翻译动作按语言逐个投递）。
 *
 * WHY 按语言拆任务：多种语言同步翻译曾在一个 HTTP 请求里跑 5~10 分钟，
 * nginx/PHP-FPM 掐断连接后 Livewire 只能报「页面加载出错」。
 * 拆成每语言一个任务后，调度器（queue:work --stop-when-empty，每分钟）
 * 逐个消化，单任务 1~2 分钟，语言之间互不影响；失败只影响单语言，
 * 覆盖率矩阵会显示缺口，运营方可重新提交补齐。
 */
class TranslatePostJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * 不自动重试：LLM 调用按 token 计费，失败重试会翻倍烧钱；
     * 失败语言在覆盖率矩阵里仍是缺口，运营可手动重新提交。
     */
    public int $tries = 1;

    /** 长文翻译（两次 LLM 调用）可能超过 1 分钟 */
    public int $timeout = 900;

    public function __construct(
        public int $postId,
        public string $locale,
    ) {}

    public function handle(): void
    {
        $post = Post::find($this->postId);

        if ($post === null) {
            return; // 文章已被删除
        }

        // 幂等：任务排队期间该语言可能已被翻译（重复提交/手动翻译），跳过
        if (filled($post->getTranslation('title', $this->locale, false))) {
            return;
        }

        try {
            app(PostTranslator::class)->translate($post, [$this->locale]);
        } catch (\Throwable $e) {
            // 源内容缺失等顶层错误：记录不重试（LLM 成本），矩阵显示缺口即可
            report($e);
        }
    }
}
