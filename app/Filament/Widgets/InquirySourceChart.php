<?php

namespace App\Filament\Widgets;

use App\Models\Inquiry;
use Filament\Widgets\ChartWidget;

/**
 * 询盘来源分布：近 90 天按 utm.source（无则回退 source 字段）分组，
 * 取前 5 个，其余合并为 other。
 */
class InquirySourceChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected int | string | array $columnSpan = 'full';

    protected ?string $maxHeight = '300px';

    public function getHeading(): \Illuminate\Contracts\Support\Htmlable | string | null
    {
        return __('admin.inquiries.source_heading');
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $rows = Inquiry::query()
            ->where('created_at', '>=', now()->subDays(90))
            ->get(['utm', 'source']);

        $counts = [];

        foreach ($rows as $row) {
            $utm = is_array($row->utm) ? $row->utm : [];

            $name = trim((string) ($utm['source'] ?? ''));
            if ($name === '') {
                $name = (string) ($row->source ?? '');
            }
            if ($name === '') {
                $name = 'other';
            }

            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }

        arsort($counts);

        $sourceOptions = __('admin.inquiries.source_options');

        // 前 5 名保留，其余合并为 other
        $top = array_slice($counts, 0, 5, true);
        $rest = array_slice($counts, 5, null, true);

        $labels = [];
        $values = [];

        foreach ($top as $name => $count) {
            $labels[] = $sourceOptions[$name] ?? $name;
            $values[] = $count;
        }

        if ($rest !== []) {
            $labels[] = __('admin.inquiries.source_other');
            $values[] = array_sum($rest);
        }

        return [
            'datasets' => [
                [
                    'data' => $values,
                    'backgroundColor' => [
                        'rgb(245, 158, 11)',
                        'rgb(59, 130, 246)',
                        'rgb(34, 197, 94)',
                        'rgb(239, 68, 68)',
                        'rgb(139, 92, 246)',
                        'rgb(107, 114, 128)',
                    ],
                ],
            ],
            'labels' => $labels,
        ];
    }
}
