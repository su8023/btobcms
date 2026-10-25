<?php

namespace Modules\UpdateCenter\Providers;

use Modules\UpdateCenter\Settings\PluginSettings;
use Nwidart\Modules\Support\ModuleServiceProvider;

class UpdateCenterServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'UpdateCenter';

    protected string $nameLower = 'updatecenter';

    public function boot(): void
    {
        parent::boot();

        $this->mergeConfigFrom(__DIR__.'/../../config/config.php', 'update-center');
    }

    /**
     * 插件设置面板字段（/admin/plugins/UpdateCenter/settings）。
     */
    public static function settingsSchema(): string
    {
        return PluginSettings::class;
    }
}
