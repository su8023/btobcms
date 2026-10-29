<?php

namespace Modules\LeadMagnet\Providers;

use Illuminate\Support\Facades\Route;
use Modules\LeadMagnet\Http\Controllers\MagnetDownloadController;
use Nwidart\Modules\Support\ModuleServiceProvider;

class LeadMagnetServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'LeadMagnet';

    protected string $nameLower = 'lead-magnet';

    public function boot(): void
    {
        parent::boot();

        $this->mergeConfigFrom(__DIR__.'/../../config/config.php', 'lead-magnet');

        // 基类已用 nameLower('lead-magnet') 注册视图命名空间；
        // 本插件统一使用 'leadmagnet' 命名空间，再注册一次别名
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'leadmagnet');

        if (! config('lead-magnet.enabled')) {
            return;
        }

        // 签名下载路由只注册一次：不能放进 routes/site.php（site.php 会在
        // 每个语言组里 include，同名路由会重复注册冲突）。
        // 无语言前缀、不参与 localized_route，签名 URL 16 小时内全语言通用。
        Route::middleware(['web', 'signed'])
            ->get('/download/file/{download}', [MagnetDownloadController::class, 'download'])
            ->name('magnet.file');
    }
}
