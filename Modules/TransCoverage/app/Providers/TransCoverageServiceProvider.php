<?php

namespace Modules\TransCoverage\Providers;

use Nwidart\Modules\Support\ModuleServiceProvider;

class TransCoverageServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'TransCoverage';

    protected string $nameLower = 'trans-coverage';

    public function boot(): void
    {
        parent::boot();

        $this->mergeConfigFrom(__DIR__.'/../../config/config.php', 'trans-coverage');
        // 看板视图命名空间：transcoverage::coverage
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'transcoverage');
    }
}
