<?php

namespace App\Filament\Widgets;

use App\Models\Inquiry;
use Filament\Widgets\ChartWidget;

/**
 * 询盘趋势图：近 30 天（含当天）每日新增询盘数。
 */
class InquiryTrendChart extends ChartWidget
{
    protected static ?int $sort = 1;

    protected int | string | array $columnSpan = 'full';

    protected ?string $maxHeight = '300px';

    public function getHeading(): \Illuminate\Contracts\Support\Htmlable | string | null
    {
        return __('admin.inquiries.trend_heading');
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $start = now()->subDays(29)->startOfDay();

        $rawCounts = Inquiry::query()
            ->where('created_at', '>=', $start)
            ->selectRaw('DATE(created_at) as day, COUNT(*) as aggregate')
            ->groupBy('day')
            ->pluck('aggregate', 'day');

        // 兼容不同驱动（SQLite/MySQL 返回字符串，PostgreSQL 可能返回日期对象）
        $counts = [];
        foreach ($rawCounts as $day => $total) {
            $key = $day instanceof \DateTimeInterface ? $day->format('Y-m-d') : (string) $day;
            $counts[$key] = (int) $total;
        }

        $labels = [];
        $values = [];

        for ($i = 29; $i >= 0; $i--) {
            $key = now()->subDays($i)->startOfDay()->format('Y-m-d');
            $labels[] = $key;
            $values[] = $counts[$key] ?? 0;
        }

        return [
            'datasets' => [
                [
                    'label' => __('admin.inquiries.trend_dataset'),
                    'data' => $values,
                    'borderColor' => 'rgb(245, 158, 11)',
                    'backgroundColor' => 'rgba(245, 158, 11, 0.15)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
            ],
            'labels' => $labels,
        ];
    }
}
