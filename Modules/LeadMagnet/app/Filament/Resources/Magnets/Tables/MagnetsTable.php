<?php

namespace Modules\LeadMagnet\Filament\Resources\Magnets\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class MagnetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                // title 是多语言 JSON：按默认语言取值展示
                TextColumn::make('title')
                    ->label('标题')
                    ->formatStateUsing(fn ($record) => $record->getTranslation('title', default_locale()) ?: $record->slug)
                    ->searchable(),

                TextColumn::make('slug')
                    ->label('URL 标识')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('downloads_count')
                    ->label('下载次数')
                    ->sortable()
                    ->badge(),

                TextColumn::make('active')
                    ->label('状态')
                    ->badge()
                    ->formatStateUsing(fn (bool $state): string => $state ? '启用' : '停用')
                    ->color(fn (bool $state) => $state ? 'success' : 'gray'),

                TextColumn::make('updated_at')
                    ->label('更新于')
                    ->since()
                    ->toggleable(),
            ])
            ->filters([
                TernaryFilter::make('active')->label('启用'),
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
