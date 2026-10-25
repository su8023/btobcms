<?php

namespace App\Filament\Resources\AttrSets\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AttrSetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('admin.common.name'))->searchable(),

                TextColumn::make('code')->label(__('admin.common.code'))->badge()->searchable(),

                TextColumn::make('attributes_count')
                    ->counts('attributes')
                    ->label(__('admin.attr_sets.attributes_count'))
                    ->sortable(),

                TextColumn::make('categories_count')
                    ->counts('categories')
                    ->label(__('admin.attr_sets.used_by_categories')),
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
