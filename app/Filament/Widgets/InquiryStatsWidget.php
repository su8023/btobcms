<?php

namespace App\Filament\Widgets;

use App\Models\Inquiry;
use App\Models\Post;
use App\Models\Product;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class InquiryStatsWidget extends StatsOverviewWidget
{
    protected static ?int $sort = 0;

    protected function getStats(): array
    {
        return [
            Stat::make(__('admin.stats.new_7d'), Inquiry::query()
                ->where('status', Inquiry::STATUS_NEW)
                ->where('created_at', '>=', now()->subDays(7))
                ->count())
                ->description(__('admin.stats.unread_total', ['count' => Inquiry::query()->where('status', Inquiry::STATUS_NEW)->count()]))
                ->color('info'),

            Stat::make(__('admin.stats.this_month'), Inquiry::query()
                ->where('created_at', '>=', now()->startOfMonth())
                ->count())
                ->color('success'),

            Stat::make(__('admin.stats.published_products'), Product::published()->count())
                ->description(__('admin.stats.drafts', ['count' => Product::where('status', 'draft')->count()]))
                ->color('primary'),

            Stat::make(__('admin.stats.awaiting_review'), Post::query()
                ->whereIn('status', [Post::STATUS_DRAFT, Post::STATUS_REVIEW])
                ->count())
                ->color('warning'),
        ];
    }
}
