<?php

namespace Modules\AiSeoAuditor\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Modules\AiSeoAuditor\Console\Commands\SeoAuditCommand;
use Nwidart\Modules\Support\ModuleServiceProvider;

class AiSeoAuditorServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'AiSeoAuditor';

    protected string $nameLower = 'aiseoauditor';

    protected array $commands = [
        SeoAuditCommand::class,
    ];

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    protected function configureSchedules(Schedule $schedule): void
    {
        // 每日自动巡检：巡检小时/开关/站点地址均可在插件中心设置（数据库优先）
        $hour = min(23, max(0, (int) plugin_setting('ai-seo-auditor', 'schedule_hour', 6)));

        try {
            $schedule->command('seo:audit')
                ->dailyAt(sprintf('%02d:00', $hour))
                // 全站串行爬取最坏 ~17 分钟，防上一轮未结束时 cron 再拉起一轮
                ->withoutOverlapping()
                ->when(fn () => plugin_setting('ai-seo-auditor', 'enabled', true) !== false
                    && plugin_setting('ai-seo-auditor', 'enabled', true) !== 'false');
        } catch (\Throwable) {
            // 设置表不可用时按默认时间执行
            $schedule->command('seo:audit')->dailyAt(sprintf('%02d:00', $hour))->withoutOverlapping();
        }
    }
}
