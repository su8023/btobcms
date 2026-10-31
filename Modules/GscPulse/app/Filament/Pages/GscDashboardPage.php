<?php

namespace Modules\GscPulse\Filament\Pages;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Modules\GscPulse\Models\GscCredential;
use Modules\GscPulse\Models\GscDaily;
use Modules\GscPulse\Services\GscFetcher;
use Modules\GscPulse\Services\GscReport;
use Throwable;

/**
 * GSC 数据看板（/admin/gsc-pulse）：近 28 天总点击/展现/平均排名、
 * 临门一脚 / 衰退预警 / 机会词三个榜单、分语言点击统计。
 * 聚合逻辑全部在 GscReport（纯数据库计算），本页只负责取数与「立即拉取」触发。
 */
class GscDashboardPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?int $navigationSort = 34;

    protected static ?string $slug = 'gsc-pulse';

    protected string $view = 'gscpulse::gsc-dashboard';

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return __('admin.groups.growth');
    }

    public static function getNavigationLabel(): string
    {
        return 'GSC 看板';
    }

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return 'GSC 数据看板（Google Search Console）';
    }

    /**
     * 功能未启用时改用「未启用」视图。
     *
     * WHY：后台页面由核心按「模块启用」发现（App\Support\PluginPages），与本插件自己的
     * enabled 开关无关——开关关掉后页面仍在导航里，此时既不该展示历史数据，也不该
     * 提供拉取入口，统一走「未启用」空态，避免误导。
     */
    public function getView(): string
    {
        return GscConnectPage::isEnabled() ? $this->view : 'gscpulse::gsc-disabled';
    }

    /**
     * 页头「立即拉取」：无凭据先提示连接；有凭据则同步调 GscFetcher。
     */
    public function fetchNow(): void
    {
        if (! GscConnectPage::isEnabled()) {
            Notification::make()
                ->title('GSC 数据回流功能未启用')
                ->body('请先在插件中心开启 GscPulse 插件后再拉取数据。')
                ->warning()
                ->send();

            return;
        }

        if (! GscCredential::isConnected()) {
            Notification::make()
                ->title('尚未连接 Google Search Console')
                ->body('请先到「GSC 连接」页填写 OAuth 凭据并完成授权。')
                ->warning()
                ->send();

            return;
        }

        // 与每日定时任务共用一把缓存锁：已在跑就别再起一轮，避免重复写入
        if (GscFetcher::isRunning()) {
            Notification::make()
                ->title('已有拉取任务在运行中')
                ->body('手动拉取与每日定时任务互斥，请等当前这轮结束再试。')
                ->warning()
                ->send();

            return;
        }

        try {
            $count = app(GscFetcher::class)->fetch();

            Notification::make()
                ->title('GSC 数据拉取完成')
                ->body("写入/更新 {$count} 行每日表现数据。")
                ->success()
                ->send();
        } catch (Throwable $e) {
            Log::warning('[GscPulse] 手动拉取失败：'.$e->getMessage());

            Notification::make()
                ->title('GSC 数据拉取失败')
                ->body(mb_substr($e->getMessage(), 0, 200))
                ->danger()
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        if (! GscConnectPage::isEnabled()) {
            return []; // 未启用：不给拉取入口
        }

        return [
            Action::make('fetchNow')
                ->label('立即拉取')
                ->action(fn () => $this->fetchNow()),
        ];
    }

    public function getViewData(): array
    {
        if (! GscConnectPage::isEnabled()) {
            // 未启用：不查库不取数，直接给空态（视图已切到「未启用」页）
            return [
                'enabled' => false,
                'connected' => false,
                'siteUrl' => '',
                'days' => (int) config('gsc-pulse.days_back', 28),
                'connectUrl' => GscConnectPage::getUrl(),
                'stats' => ['clicks' => 0, 'impressions' => 0, 'position' => null],
                'nearMiss' => collect(),
                'declining' => collect(),
                'opportunities' => collect(),
                'byLanguage' => [],
                'hasData' => false,
            ];
        }

        $report = app(GscReport::class);
        $siteUrl = GscCredential::defaultSiteUrl();

        return [
            'enabled' => true,
            'connected' => GscCredential::isConnected(),
            'siteUrl' => $siteUrl,
            'days' => (int) config('gsc-pulse.days_back', 28),
            'connectUrl' => GscConnectPage::getUrl(),
            'stats' => $report->stats($siteUrl),
            'nearMiss' => $report->nearMiss($siteUrl),
            'declining' => $report->declining($siteUrl),
            'opportunities' => $report->opportunities($siteUrl),
            'byLanguage' => $report->byLanguage($siteUrl),
            // 是否有过任何数据（决定空态文案：提示连接 vs 提示拉取）
            'hasData' => GscDaily::query()->where('site_url', $siteUrl)->exists(),
        ];
    }
}
