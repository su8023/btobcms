<?php

namespace App\Filament\Resources\Inquiries\RelationManagers;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class FollowupsRelationManager extends RelationManager
{
    protected static string $relationship = 'followups';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.followups.title');
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('channel')
                    ->label(__('admin.followups.channel'))
                    ->options(__('admin.followups.channel_options'))
                    ->default('email')
                    ->required(),

                Textarea::make('content')
                    ->label(__('admin.followups.content'))
                    ->rows(4)
                    ->required()
                    ->columnSpanFull(),

                DateTimePicker::make('next_action_at')
                    ->label(__('admin.followups.next_followup_at')),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('user.name')->label(__('admin.followups.by')),

                TextColumn::make('channel')
                    ->label(__('admin.followups.channel'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __('admin.followups.channel_options')[$state] ?? $state),

                TextColumn::make('content')->label(__('admin.followups.content'))->limit(80)->wrap(),

                TextColumn::make('next_action_at')
                    ->label(__('admin.followups.next_followup_at'))
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('—'),

                TextColumn::make('created_at')->label(__('admin.common.created_at'))->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                CreateAction::make()
                    ->mutateDataUsing(fn (array $data) => array_merge($data, [
                        'user_id' => auth()->id(),
                    ])),
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
