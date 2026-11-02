<?php

namespace Modules\AiContentWriter\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Modules\AiContentWriter\Support\RecordTranslation;
use Modules\AiContentWriter\Support\TranslationProgress;

/**
 * 单语言记录（产品 / 分类）翻译任务（一键翻译按语言逐个投递）。
 *
 * 与 TranslatePostJob 同一设计：每语言一个任务、ai-translate 独立队列、
 * $tries=1（LLM 按 token 计费不自动重试）、失败只影响单语言不拖累其余。
 * 翻译器与主字段按 type 从 RecordTranslation 映射解析，覆盖进度键带 type 维度。
 */
class TranslateRecordJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(
        public int $recordId,
        public string $locale,
        public string $type,
    ) {
        $this->onQueue('ai-translate');
    }

    public function handle(): void
    {
        $entry = RecordTranslation::resolve($this->type);

        if ($entry === null) {
            return; // 未知类型
        }

        [$modelClass, $translatorClass, $titleField] = $entry;

        $record = $modelClass::find($this->recordId);

        if ($record === null) {
            return; // 已被删除
        }

        // 幂等：排队期间该语言可能已被翻译（重复提交/手动翻译），跳过
        if (filled($record->getTranslation($titleField, $this->locale, false))) {
            TranslationProgress::mark($this->recordId, $this->locale, TranslationProgress::DONE, '语言已存在，跳过重复翻译', $this->type);

            return;
        }

        TranslationProgress::mark($this->recordId, $this->locale, TranslationProgress::RUNNING, '', $this->type);

        try {
            $translated = app($translatorClass)->translate($record, [$this->locale]);

            TranslationProgress::mark($this->recordId, $this->locale,
                $translated === [] ? TranslationProgress::FAILED : TranslationProgress::DONE,
                $translated === [] ? 'AI 输出无法解析（详见日志/留档）' : '',
                $this->type);

            $this->forgetCoverageCache();
        } catch (\Throwable $e) {
            TranslationProgress::mark($this->recordId, $this->locale, TranslationProgress::FAILED, $e->getMessage(), $this->type);

            report($e);
        }
    }

    /** 翻译落库后使覆盖率扫描缓存失效，看板轮询即时反映（TransCoverage 未启用则静默跳过） */
    private function forgetCoverageCache(): void
    {
        try {
            if (class_exists(\Modules\TransCoverage\Services\CoverageScanner::class)) {
                Cache::forget(app(\Modules\TransCoverage\Services\CoverageScanner::class)->cacheKey());
            }
        } catch (\Throwable) {
            // 缓存驱动异常不影响翻译结果落库
        }
    }
}
