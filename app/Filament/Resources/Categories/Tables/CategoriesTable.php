<?php

namespace App\Filament\Resources\Categories\Tables;

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
                    ->getStateUsing(fn (Model $record): string => RecordTranslation::badge($record, 'category')['label'])
                    ->color(fn (Model $record): string => RecordTranslation::badge($record, 'category')['color'])
                    ->visible(fn (): bool => class_exists(RecordTranslation::class)),

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
                    ->visible(fn (Model $record): bool => RecordTranslation::available('category')
                        && RecordTranslation::badge($record, 'category')['hasGap'])
                    ->action(function (Model $record): void {
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
                        ->visible(fn (): bool => RecordTranslation::available('category'))
                        ->action(function (Collection $records): void {
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
}
