<?php

namespace Modules\LinkWeaver\Providers;

use App\Events\PostPublished;
use Illuminate\Support\Facades\Event;
use Modules\LinkWeaver\Listeners\WeavePostLinks;
use Nwidart\Modules\Support\ModuleServiceProvider;

class LinkWeaverServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'LinkWeaver';

    protected string $nameLower = 'linkweaver';

    public function boot(): void
    {
        parent::boot();

        $this->mergeConfigFrom(__DIR__.'/../../config/config.php', 'link-weaver');

        if (config('link-weaver.enabled')) {
            Event::listen(PostPublished::class, WeavePostLinks::class);
        }
    }
}
