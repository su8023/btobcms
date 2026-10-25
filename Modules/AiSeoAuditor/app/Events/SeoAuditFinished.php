<?php

namespace Modules\AiSeoAuditor\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SeoAuditFinished
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $baseUrl,
        public int $pagesChecked,
        public int $issueCount,
        public array $summary,
        public string $reportPath,
    ) {
    }
}
