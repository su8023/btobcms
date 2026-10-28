<?php

namespace Modules\LeadLens\Providers;

use App\Events\InquiryCreated;
use Illuminate\Support\Facades\Event;
use Modules\LeadLens\Listeners\EnrichInquiry;
use Nwidart\Modules\Support\ModuleServiceProvider;

class LeadLensServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'LeadLens';

    protected string $nameLower = 'leadlens';

    public function boot(): void
    {
        parent::boot();

        $this->mergeConfigFrom(__DIR__.'/../../config/config.php', 'lead-lens');

        if (config('lead-lens.enabled')) {
            Event::listen(InquiryCreated::class, EnrichInquiry::class);
        }
    }
}
