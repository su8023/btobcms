<?php

namespace Modules\AiContentWriter\Jobs;

use App\Models\Post;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Modules\AiContentWriter\Services\PostTranslator;
use Modules\AiContentWriter\Support\TranslationProgress;
use Modules\TransCoverage\Services\CoverageScanner;

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
    ) {
        // 独立队列：单次翻译最长 15 分钟，与询盘邮件等秒级任务共用 default
        // 会队头阻塞快任务（复核 P2-5）。调度器按 default,ai-translate 顺序消费。
        // （不用属性声明 $queue：Queueable trait 已定义，类型不兼容会组合失败）
        $this->onQueue('ai-translate');
    }

    public function handle(): void
    {
        $post = Post::find($this->postId);

        if ($post === null) {
            return; // 文章已被删除
        }

        // 幂等：任务排队期间该语言可能已被翻译（重复提交/手动翻译），跳过
        if (filled($post->getTranslation('title', $this->locale, false))) {
            TranslationProgress::mark($this->postId, $this->locale, 'done', '语言已存在，跳过重复翻译');

            return;
        }

        TranslationProgress::mark($this->postId, $this->locale, 'running');

        try {
            $translated = app(PostTranslator::class)->translate($post, [$this->locale]);

            TranslationProgress::mark($this->postId, $this->locale,
                $translated === [] ? 'failed' : 'done',
                $translated === [] ? 'AI 输出无法解析（详见日志/留档）' : '');

            // 覆盖率看板矩阵刷新：缓存 key 随内容指纹变化，直接 forget 旧 key 保证立即更新
            $this->forgetCoverageCache();
        } catch (\Throwable $e) {
            // 源内容缺失等顶层错误：记录不重试（LLM 成本），矩阵显示缺口即可
            TranslationProgress::mark($this->postId, $this->locale, 'failed', $e->getMessage());

            report($e);
        }
    }

    /**
     * 翻译落库后使覆盖率扫描缓存失效（10 分钟 TTL 的 scan 结果），
     * 看板轮询时矩阵即时反映补齐结果。TransCoverage 未启用时静默跳过。
     */
    private function forgetCoverageCache(): void
    {
        try {
            if (class_exists(CoverageScanner::class)) {
                Cache::forget(app(CoverageScanner::class)->cacheKey());
            }
        } catch (\Throwable) {
            // 缓存驱动异常不影响翻译结果落库
        }
    }
}
