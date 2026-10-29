<?php

namespace App\Filament\Resources\Posts\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PostsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('title')
                    ->label(__('admin.posts.title'))
                    ->limit(60)
                    ->searchable(),

                TextColumn::make('type')
                    ->label(__('admin.common.type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __('admin.posts.type_options')[$state] ?? $state),

                TextColumn::make('status')
                    ->label(__('admin.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __('admin.posts.status_options')[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'published' => 'success',
                        'review' => 'warning',
                        default => 'gray',
                    }),

                IconColumn::make('is_ai_generated')
                    ->label(__('admin.posts.ai'))
                    ->boolean(),

                TextColumn::make('author.name')->label(__('admin.posts.author'))->toggleable(),

                TextColumn::make('published_at')
                    ->label(__('admin.common.published_at'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),

                TextColumn::make('views')->label(__('admin.common.views'))->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.common.status'))
                    ->options(__('admin.posts.status_options')),
                SelectFilter::make('type')
                    ->label(__('admin.common.type'))
                    ->options(__('admin.posts.type_options')),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('还没有文章')
            ->emptyStateDescription('两种方式开始：点右上角「新建」自己写；或去左侧「AI 写作室」，填一个主题让 AI 帮你写好草稿。')
            ->emptyStateIcon('heroicon-o-document-text');
    }
}
