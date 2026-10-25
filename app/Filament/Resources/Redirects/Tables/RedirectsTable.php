<?php

namespace App\Filament\Resources\Redirects\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RedirectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('from_path')
                    ->label(__('admin.redirects.from'))
                    ->searchable()
                    ->limit(50),

                TextColumn::make('to_path')
                    ->label(__('admin.redirects.to'))
                    ->searchable()
                    ->limit(50),

                TextColumn::make('code')->label(__('admin.common.code'))->badge(),

                TextColumn::make('hits')
                    ->label(__('admin.redirects.hits'))
                    ->sortable(),
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
