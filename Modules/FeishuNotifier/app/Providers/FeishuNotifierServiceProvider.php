<?php

namespace Modules\FeishuNotifier\Providers;

use App\Events\InquiryCreated;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Modules\AiSeoAuditor\Events\SeoAuditFinished;
use Modules\FeishuNotifier\Console\Commands\FeishuTestCommand;
use Modules\FeishuNotifier\Listeners\SendAuditReport;
use Modules\FeishuNotifier\Listeners\SendInquiryNotification;
use Nwidart\Modules\Support\ModuleServiceProvider;

class FeishuNotifierServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'FeishuNotifier';

    protected string $nameLower = 'feishunotifier';

    protected array $commands = [
        FeishuTestCommand::class,
    ];

    protected array $providers = [
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        $this->mergeConfigFrom(__DIR__.'/../../config/config.php', 'feishu-notifier');

        // 插件通过监听核心事件接入，不改动任何核心代码
        Event::listen(InquiryCreated::class, SendInquiryNotification::class);
        Event::listen(SeoAuditFinished::class, SendAuditReport::class);
    }

    protected function configureSchedules(Schedule $schedule): void
    {
        //
    }
}
