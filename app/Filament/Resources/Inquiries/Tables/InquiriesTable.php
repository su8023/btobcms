<?php

namespace App\Filament\Resources\Inquiries\Tables;

use App\Models\Inquiry;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InquiriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')->label(__('admin.inquiries.id'))->sortable(),

                TextColumn::make('name')
                    ->label(__('admin.inquiries.fields.name'))
                    ->description(fn ($record) => $record->company)
                    ->searchable(),

                TextColumn::make('email')->label(__('admin.inquiries.fields.email'))->searchable()->copyable(),

                TextColumn::make('country')->label(__('admin.inquiries.fields.country'))->badge()->toggleable(),

                TextColumn::make('status')
                    ->label(__('admin.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __('admin.inquiries.status_options')[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        Inquiry::STATUS_NEW => 'info',
                        Inquiry::STATUS_READ => 'gray',
                        Inquiry::STATUS_FOLLOWING => 'warning',
                        Inquiry::STATUS_QUOTED => 'primary',
                        Inquiry::STATUS_WON => 'success',
                        Inquiry::STATUS_LOST, Inquiry::STATUS_SPAM => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('assignee.name')
                    ->label(__('admin.inquiries.owner'))
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('source')
                    ->label(__('admin.inquiries.source'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __('admin.inquiries.source_options')[$state] ?? $state)
                    ->toggleable(),

                TextColumn::make('ip')
                    ->label(__('admin.inquiries.ip'))
                    ->copyable()
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('user_agent')
                    ->label(__('admin.inquiries.user_agent'))
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('message')
                    ->label(__('admin.inquiries.message'))
                    ->limit(60)
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label(__('admin.inquiries.received'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.common.status'))
                    ->options(__('admin.inquiries.status_options'))
                    ->multiple(),
                SelectFilter::make('assignee')
                    ->label(__('admin.inquiries.owner'))
                    ->relationship('assignee', 'name'),
            ])
            ->recordActions([
                Action::make('markRead')
                    ->label(__('admin.inquiries.mark_read'))
                    ->icon('heroicon-o-check')
                    ->color('gray')
                    ->visible(fn ($record) => $record->status === Inquiry::STATUS_NEW)
                    ->action(fn ($record) => $record->update(['status' => Inquiry::STATUS_READ])),

                Action::make('markSpam')
                    ->label(__('admin.inquiries.mark_spam'))
                    ->icon('heroicon-o-shield-exclamation')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn ($record) => $record->update(['status' => Inquiry::STATUS_SPAM])),

                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                // 跳转到 /admin/inquiries/export 下载 CSV（路由支持 ?status= 筛选）
                Action::make('exportCsv')
                    ->label(__('admin.inquiries.export_csv'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->url(fn (): string => route('admin.inquiries.export')),

                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
