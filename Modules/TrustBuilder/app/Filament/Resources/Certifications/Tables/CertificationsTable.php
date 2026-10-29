<?php

namespace Modules\TrustBuilder\Filament\Resources\Certifications\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CertificationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('name')
                    ->label(__('trustbuilder.admin.cert_name'))
                    // name 是多语言 JSON：按默认语言取值展示
                    ->formatStateUsing(fn ($record) => $record->getTranslation('name', default_locale()))
                    ->searchable(),

                TextColumn::make('issuer')
                    ->label(__('trustbuilder.admin.issuer'))
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('cert_no')
                    ->label(__('trustbuilder.admin.cert_no'))
                    ->copyable()
                    ->placeholder('—'),

                ImageColumn::make('image')
                    ->label(__('trustbuilder.admin.image'))
                    ->disk('public')
                    ->placeholder('—'),

                TextColumn::make('issued_at')
                    ->label(__('trustbuilder.admin.issued_at'))
                    ->date()
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('expires_at')
                    ->label(__('trustbuilder.admin.expires_at'))
                    ->date()
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('sort')
                    ->label(__('trustbuilder.admin.sort'))
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
