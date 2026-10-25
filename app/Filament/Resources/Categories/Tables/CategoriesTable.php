<?php

namespace App\Filament\Resources\Categories\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class CategoriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('path')
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin.categories.label'))
                    ->formatStateUsing(fn ($record) => str_repeat('— ', substr_count((string) $record->path, '/')).($record->name ?: $record->slug))
                    ->searchable(),

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
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
