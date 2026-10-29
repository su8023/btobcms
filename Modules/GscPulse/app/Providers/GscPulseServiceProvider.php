<?php

namespace Modules\GscPulse\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Modules\GscPulse\Http\Controllers\GscOAuthCallbackController;
use Modules\GscPulse\Models\GscCredential;
use Modules\GscPulse\Services\GscFetcher;
use Nwidart\Modules\Support\ModuleServiceProvider;
use Throwable;

class GscPulseServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'GscPulse';

    protected string $nameLower = 'gsc-pulse';

    public function boot(): void
    {
        parent::boot();

        $this->mergeConfigFrom(__DIR__.'/../../config/config.php', 'gsc-pulse');
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'gscpulse');

        if (! config('gsc-pulse.enabled')) {
            return;
        }

        // OAuth 回调：连接页「连接 Google」完成授权后 Google 跳回这里（code 换 token）。
        // 挂 web+auth，与管理后台同登录态；路径与 Filament 页面路由无冲突。
        Route::middleware(['web', 'auth'])
            ->get('/admin/gsc-pulse/callback', GscOAuthCallbackController::class)
            ->name('gsc-pulse.callback');
    }

    /**
     * 模块调度：每日 06:10 回流 GSC 数据。
     * 无凭据静默跳过；任何异常只记日志，不影响同一调度器里的其他任务。
     */
    protected function configureSchedules(Schedule $schedule): void
    {
        $schedule->call(function (): void {
            try {
                if (! GscCredential::isConnected()) {
                    return; // 未连接：静默跳过，不算错误
                }

                $count = app(GscFetcher::class)->fetch();

                Log::info("[GscPulse] 每日回流完成：{$count} 行。");
            } catch (Throwable $e) {
                Log::warning('[GscPulse] 每日回流失败：'.$e->getMessage());
            }
        })->name('gsc-pulse.daily-fetch')->dailyAt('06:10')->withoutOverlapping();

        // 明细保留 90 天：看板只用 28 天窗口，防 page×query×country 三维行数无限膨胀
        $schedule->call(function (): void {
            try {
                $pruned = \Modules\GscPulse\Models\GscDaily::query()
                    ->where('date', '<', now()->subDays(90))
                    ->delete();

                if ($pruned > 0) {
                    Log::info("[GscPulse] 清理 90 天前明细 {$pruned} 行。");
                }
            } catch (Throwable $e) {
                Log::warning('[GscPulse] 明细清理失败：'.$e->getMessage());
            }
        })->name('gsc-pulse.prune')->dailyAt('06:40');
    }
}
