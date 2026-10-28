<?php

namespace Modules\LeadLens\Filament\Resources;

use App\Models\Inquiry;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Modules\LeadLens\Filament\Resources\LeadRadar\Pages\ListLeads;
use Modules\LeadLens\Models\InquiryEnrichment;

/**
 * 线索雷达：按热度分层的询盘视图（只读，编辑跳转核心询盘页）。
 */
class LeadRadarResource extends Resource
{
    protected static ?string $model = Inquiry::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return '增长'; // @phpstan-ignore-line
    }

    public static function getNavigationLabel(): string
    {
        return '线索雷达';
    }

    public static function getNavigationBadge(): ?string
    {
        $hot = InquiryEnrichment::query()->where('tier', 'hot')->count();

        return $hot > 0 ? (string) $hot : null;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query
                ->with('enrichment')
                ->withAggregate('enrichment as enrichment_score', 'score'))
            ->groups([
                Group::make('enrichment.tier')->label('热度'),
            ])
            ->defaultGroup('enrichment.tier')
            ->defaultSort('enrichment_score', 'desc')
            ->columns([
                TextColumn::make('enrichment_score')
                    ->label('评分')
                    ->badge()
                    ->color(fn ($state) => $state >= 70 ? 'danger' : ($state >= 40 ? 'warning' : 'gray'))
                    ->sortable(),
                TextColumn::make('name')->label('客户')->searchable(),
                TextColumn::make('email')->label('邮箱')->copyable()
                    ->description(fn ($record) => $record->enrichment?->is_free_email ? '免费邮箱' : ($record->enrichment?->company_site ?? '')),
                TextColumn::make('company')->label('公司')->placeholder('—'),
                TextColumn::make('country')->label('国家')->badge(),
                TextColumn::make('items_count')->counts('items')->label('产品'),
                TextColumn::make('created_at')->label('时间')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('tier')
                    ->label('热度')
                    ->options(['hot' => '热线', 'warm' => '温线', 'cold' => '冷线'])
                    ->query(fn ($query, array $data) => $data['value'] !== null
                        ? $query->whereHas('enrichment', fn ($q) => $q->where('tier', $data['value']))
                        : $query),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('处理')
                    ->icon(Heroicon::ArrowTopRightOnSquare)
                    ->url(fn ($record) => "/admin/inquiries/{$record->id}/edit"),
            ])
            ->emptyStateHeading('暂无线索评分数据')
            ->emptyStateDescription('新的询盘进来自动评分分层；历史询盘可在首次访问时逐条打开补评。');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeads::route('/'),
        ];
    }
}
