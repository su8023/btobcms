<?php

namespace App\Filament\Resources\Attributes\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class AttributesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin.attributes.name_label'))
                    ->formatStateUsing(fn ($state) => is_array($state) ? ($state['en'] ?? '') : (string) $state)
                    ->searchable(),

                TextColumn::make('attrSet.name')->label(__('admin.attributes.template'))->badge(),

                TextColumn::make('code')->label(__('admin.common.code'))->badge()->searchable(),

                TextColumn::make('type')
                    ->label(__('admin.common.type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __('admin.attributes.type_options')[$state] ?? $state),

                TextColumn::make('unit')->label(__('admin.common.unit'))->placeholder('—'),

                TextColumn::make('options')
                    ->label(__('admin.attributes.options'))
                    ->badge()
                    ->formatStateUsing(fn ($state) => is_array($state) ? implode(', ', $state) : '')
                    ->toggleable(),

                TextColumn::make('is_filterable')
                    ->label(__('admin.attributes.filterable'))
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? __('admin.common.yes') : __('admin.common.no')),
            ])
            ->filters([
                SelectFilter::make('attr_set')->label(__('admin.attributes.template'))->relationship('attrSet', 'name'),
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
