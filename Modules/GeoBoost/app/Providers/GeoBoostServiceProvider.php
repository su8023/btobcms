<?php

namespace Modules\GeoBoost\Providers;

use App\Support\Slots;
use Illuminate\Support\Facades\Event;
use Modules\GeoBoost\Http\Middleware\TrackAiReferrer;
use Modules\GeoBoost\Listeners\CleanupPostFaqs;
use Nwidart\Modules\Support\ModuleServiceProvider;

class GeoBoostServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'GeoBoost';

    protected string $nameLower = 'geoboost';

    public function boot(): void
    {
        parent::boot();

        $this->mergeConfigFrom(__DIR__.'/../../config/config.php', 'geo-boost');
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'geoboost');

        if (! config('geo-boost.enabled')) {
            return;
        }

        // FAQ 区块注入文章正文底部（$post 在同一视图数据上下文可用）
        Slots::push('post_bottom', 'geoboost::faq-block');

        // AI 引擎来访追踪：轻量 INSERT，仅命中 referrer 时写入
        $this->app->make('router')->pushMiddlewareToGroup('web', TrackAiReferrer::class);

        // 文章删除时清理其 FAQ（morph 约束删除亦可，此处显式处理软删场景）
        Event::listen('eloquent.deleting: '.\App\Models\Post::class, CleanupPostFaqs::class);
    }
}
