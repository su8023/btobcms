<?php

namespace App\Events;

use App\Models\Inquiry;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 询盘状态流转（new → quoted / won / spam…）。
 * 插件接入示例：Event::listen(InquiryStatusChanged::class, fn ($e) => ...) 推送 CRM、同步 ERP、战报统计。
 */
class InquiryStatusChanged
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly Inquiry $inquiry,
        public readonly string $oldStatus,
        public readonly string $newStatus,
    ) {
    }
}
