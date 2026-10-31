<?php

namespace Modules\AiSeoAuditor\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\AiSeoAuditor\Services\AuditRunner;
use Throwable;

/**
 * 后台「立即巡检」的队列任务。
 *
 * 之前后台直接在 HTTP 请求里跑 Artisan::call('seo:audit')：
 * 最坏情况 100 页 × 10 秒 ≈ 17 分钟，管理员的浏览器会先被网关掐断（504），
 * 报告与飞书通知能否送达全看运气。
 * 改为排队后：请求立刻返回「已排队」，巡检在 worker 里跑完。
 *
 * 事件 SeoAuditFinished 仍由 AuditRunner 派发，
 * 飞书周报监听器与报告页的行为不变。
 */
class RunSeoAudit implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** 队列等待超时（秒）：超过就丢弃，避免堆积 */
    public int $timeout = 1800;

    /** 失败重试次数 */
    public int $tries = 1;

    public function __construct(
        public string $baseUrl,
        public int $limit = 100,
        public int $timeoutPerPage = 10,
    ) {
    }

    public function handle(AuditRunner $runner): void
    {
        $runner->run($this->baseUrl, $this->limit, $this->timeoutPerPage);
    }

    public function failed(?Throwable $e): void
    {
        \Illuminate\Support\Facades\Log::warning('[ai-seo-auditor] 巡检任务失败', [
            'base_url' => $this->baseUrl,
            'error' => $e?->getMessage(),
        ]);
    }
}
