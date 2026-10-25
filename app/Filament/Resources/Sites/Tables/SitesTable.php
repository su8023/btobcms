<?php

namespace App\Filament\Resources\Sites\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SitesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('admin.common.name'))->searchable(),

                TextColumn::make('domain')
                    ->label(__('admin.sites.domain'))
                    ->placeholder(__('admin.sites.main_site'))
                    ->searchable(),

                TextColumn::make('default_locale')->label(__('admin.sites.default_locale'))->badge(),

                TextColumn::make('locales')
                    ->label(__('admin.sites.locales'))
                    ->badge()
                    ->formatStateUsing(fn ($state) => is_array($state) ? implode(', ', $state) : ''),

                TextColumn::make('is_active')
                    ->label(__('admin.sites.is_active'))
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? __('admin.sites.active') : __('admin.sites.disabled'))
                    ->color(fn (bool $state) => $state ? 'success' : 'gray'),
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
