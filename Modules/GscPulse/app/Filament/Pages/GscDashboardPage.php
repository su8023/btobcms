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
     * 页头「立即拉取」：无凭据先提示连接；有凭据则同步调 GscFetcher。
     */
    public function fetchNow(): void
    {
        if (! GscCredential::isConnected()) {
            Notification::make()
                ->title('尚未连接 Google Search Console')
                ->body('请先到「GSC 连接」页填写 OAuth 凭据并完成授权。')
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
        return [
            Action::make('fetchNow')
                ->label('立即拉取')
                ->action(fn () => $this->fetchNow()),
        ];
    }

    public function getViewData(): array
    {
        $report = app(GscReport::class);
        $siteUrl = GscCredential::defaultSiteUrl();

        return [
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
