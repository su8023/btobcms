<?php

namespace Modules\GeoBoost\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Modules\GeoBoost\Models\AiVisit;

/**
 * AI 引流看板：ChatGPT/Perplexity 等 AI 搜索引擎带来的来访趋势。
 * 纯数据库聚合，零 AI 调用。
 */
class AiTrafficPage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cpu-chip';

    protected static ?int $navigationSort = 32;

    protected static ?string $slug = 'ai-traffic';

    protected string $view = 'geoboost::ai-traffic';

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return '增长';
    }

    public static function getNavigationLabel(): string
    {
        return 'AI 引流看板';
    }

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return 'AI 引流看板';
    }

    public function getViewData(): array
    {
        $days = (int) config('geo-boost.dashboard_days', 30);
        $since = now()->subDays($days);

        $base = AiVisit::query()->where('created_at', '>=', $since);

        $byEngine = (clone $base)
            ->select('engine', DB::raw('count(*) as total'))
            ->groupBy('engine')
            ->orderByDesc('total')
            ->get();

        $topPaths = (clone $base)
            ->select('path', DB::raw('count(*) as total'))
            ->groupBy('path')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        $daily = (clone $base)
            ->select(DB::raw('date(created_at) as day'), DB::raw('count(*) as total'))
            ->groupBy('day')
            ->orderBy('day')
            ->get();

        // 近 7 天环比
        $last7 = AiVisit::where('created_at', '>=', now()->subDays(7))->count();
        $prev7 = AiVisit::whereBetween('created_at', [now()->subDays(14), now()->subDays(7)])->count();
        $trend = $prev7 > 0 ? (int) round((($last7 - $prev7) / $prev7) * 100) : ($last7 > 0 ? 100 : 0);

        return [
            'total' => (clone $base)->count(),
            'days' => $days,
            'byEngine' => $byEngine,
            'topPaths' => $topPaths,
            'daily' => $daily,
            'last7' => $last7,
            'trend' => $trend,
            'recent' => AiVisit::query()->latest('created_at')->limit(20)->get(),
        ];
    }
}
