<?php

namespace Modules\TrustBuilder\Filament\Resources\Cases\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class CasesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('title')
                    ->label(__('trustbuilder.admin.title'))
                    // title 是多语言 JSON：按默认语言取值展示
                    ->formatStateUsing(fn ($record) => $record->getTranslation('title', default_locale()) ?: $record->slug)
                    ->searchable(),

                TextColumn::make('customer_name')
                    ->label(__('trustbuilder.admin.customer_name'))
                    ->searchable(),

                ImageColumn::make('cover')
                    ->label(__('trustbuilder.admin.cover'))
                    ->disk('public')
                    ->placeholder('—'),

                TextColumn::make('industry')
                    ->label(__('trustbuilder.admin.industry'))
                    ->badge()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('country')
                    ->label(__('trustbuilder.admin.country'))
                    ->badge()
                    ->placeholder('—')
                    ->toggleable(),

                // 已发布徽章：绿=已发布，灰=未发布
                TextColumn::make('published')
                    ->label(__('trustbuilder.admin.status'))
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state
                        ? __('trustbuilder.admin.published')
                        : __('trustbuilder.admin.unpublished'))
                    ->color(fn (bool $state) => $state ? 'success' : 'gray'),

                TextColumn::make('sort')
                    ->label(__('trustbuilder.admin.sort'))
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('published')->label(__('trustbuilder.admin.published')),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
