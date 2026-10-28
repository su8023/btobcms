<?php

namespace Modules\LeadLens\Listeners;

use App\Events\InquiryCreated;
use Illuminate\Support\Facades\Log;
use Modules\LeadLens\Models\InquiryEnrichment;
use Modules\LeadLens\Services\LeadScorer;
use Throwable;

/**
 * 询盘创建 → 自动评分增强。
 * 失败只记日志：评分失败不影响询盘入库与通知主流程。
 */
class EnrichInquiry
{
    public function __construct(private readonly LeadScorer $scorer)
    {
    }

    public function handle(InquiryCreated $event): void
    {
        try {
            $inquiry = $event->inquiry;

            InquiryEnrichment::updateOrCreate(
                ['inquiry_id' => $inquiry->id],
                $this->scorer->score($inquiry),
            );
        } catch (Throwable $e) {
            Log::warning('LeadLens: 询盘评分失败', [
                'inquiry' => $event->inquiry->id,
                'e' => $e->getMessage(),
            ]);
        }
    }
}
