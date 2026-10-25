<?php

namespace Modules\AiContentWriter\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Modules\AiContentWriter\Console\Commands\GenerateArticleCommand;
use Nwidart\Modules\Support\ModuleServiceProvider;

class AiContentWriterServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'AiContentWriter';

    protected string $nameLower = 'aicontentwriter';

    protected array $commands = [
        GenerateArticleCommand::class,
    ];

    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    protected function configureSchedules(Schedule $schedule): void
    {
        //
    }
}
