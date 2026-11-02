<?php

namespace App\Filament\Resources\Categories\Tables;

use App\Support\PluginGuard;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Modules\AiContentWriter\Support\RecordTranslation;

class CategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('path')
            ->poll(class_exists(RecordTranslation::class) ? '10s' : null)
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin.categories.label'))
                    ->formatStateUsing(fn ($record) => str_repeat('— ', substr_count((string) $record->path, '/')).($record->name ?: $record->slug))
                    ->searchable(),

                TextColumn::make('translation_status')
                    ->label(__('admin.common.translation'))
                    ->badge()
                    ->getStateUsing(fn (Model $record): string => self::translationBadge($record)['label'])
                    ->color(fn (Model $record): string => self::translationBadge($record)['color'])
                    ->visible(fn (): bool => self::translationAvailable()),

                TextColumn::make('slug')->label(__('admin.common.slug'))->searchable(),

                TextColumn::make('parent.name')
                    ->label(__('admin.categories.parent_short'))
                    ->toggleable(),

                TextColumn::make('attrSet.name')
                    ->label(__('admin.categories.attr_template_column'))
                    ->badge()
                    ->placeholder('—'),

                TextColumn::make('products_count')
                    ->counts('products')
                    ->label(__('admin.categories.products_count'))
                    ->sortable(),

                TextColumn::make('status')
                    ->label(__('admin.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? __('admin.categories.visible') : __('admin.categories.hidden'))
                    ->color(fn (bool $state) => $state ? 'success' : 'gray'),
            ])
            ->filters([
                TernaryFilter::make('status')->label(__('admin.categories.visible')),
            ])
            ->recordActions([
                EditAction::make(),

                Action::make('translate_missing')
                    ->label(__('admin.common.translate_missing'))
                    ->icon('heroicon-o-language')
                    ->visible(fn (Model $record): bool => self::translationAvailable()
                        && RecordTranslation::available('category')
                        && RecordTranslation::badge($record, 'category')['hasGap'])
                    ->action(function (Model $record): void {
                        // 兜底：页面渲染后、点击前插件可能被删除（另一标签页操作）
                        if (! self::translationAvailable()) {
                            Notification::make()
                                ->title(__('admin.common.translate_missing'))
                                ->warning()
                                ->send();

                            return;
                        }

                        $count = RecordTranslation::enqueue($record, 'category');

                        if ($count === 0) {
                            Notification::make()
                                ->title(__('admin.common.translate_no_source'))
                                ->warning()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('admin.common.translate_queued', ['count' => $count]))
                            ->body(__('admin.common.translate_queued_body'))
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),

                    BulkAction::make('translate_missing_bulk')
                        ->label(__('admin.common.translate_missing_bulk'))
                        ->icon('heroicon-o-language')
                        ->deselectRecordsAfterCompletion()
                        ->visible(fn (): bool => self::translationAvailable()
                            && RecordTranslation::available('category'))
                        ->action(function (Collection $records): void {
                            // 兜底：页面渲染后、点击前插件可能被删除（另一标签页操作）
                            if (! self::translationAvailable()) {
                                Notification::make()
                                    ->title(__('admin.common.translate_missing_bulk'))
                                    ->warning()
                                    ->send();

                                return;
                            }

                            $total = $records->sum(fn (Model $record): int => RecordTranslation::enqueue($record, 'category'));

                            if ($total === 0) {
                                Notification::make()
                                    ->title(__('admin.common.translate_bulk_none'))
                                    ->success()
                                    ->send();

                                return;
                            }

                            Notification::make()
                                ->title(__('admin.common.translate_bulk_queued', ['count' => $total]))
                                ->body(__('admin.common.translate_queued_body'))
                                ->success()
                                ->send();
                        }),
                ]),
            ]);
    }

    /**
     * AiContentWriter 插件是否可用（类存在且插件处于启用状态）。
     *
     * 插件中心的「删除插件」会真的删掉 Modules/AiContentWriter 目录：
     * 此时 RecordTranslation 类不存在，直接调用会抛
     * Error: Class "..." not found，分类列表页整个 500。
     */
    private static function translationAvailable(): bool
    {
        return PluginGuard::available(RecordTranslation::class, 'AiContentWriter');
    }

    /**
     * 翻译徽章状态。插件不可用时返回中性占位，
     * 让列保持可见但不发散（visible() 已保证正常情况下才有值）。
     */
    private static function translationBadge(Model $record): array
    {
        if (! self::translationAvailable()) {
            return ['label' => __('admin.common.translation'), 'color' => 'gray'];
        }

        return RecordTranslation::badge($record, 'category');
    }
}
