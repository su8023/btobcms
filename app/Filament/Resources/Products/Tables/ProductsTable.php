<?php

namespace App\Filament\Resources\Products\Tables;

use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Models\Attachment;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ReplicateAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultPaginationPageOption(25)
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin.common.name'))
                    ->limit(50)
                    ->searchable()
                    ->sortable(),

                TextColumn::make('category.name')
                    ->label(__('admin.products.fields.category'))
                    ->limit(30),

                TextColumn::make('slug')
                    ->label(__('admin.common.slug'))
                    ->limit(32)
                    ->searchable(),

                TextColumn::make('status')
                    ->label(__('admin.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => __('admin.products.status_options')[$state] ?? $state)
                    ->color(fn (string $state): string => match ($state) {
                        'published' => 'success',
                        'draft' => 'gray',
                        default => 'warning',
                    }),

                TextColumn::make('price_min')
                    ->label(__('admin.products.fields.price'))
                    ->formatStateUsing(fn ($record) => $record->price_min === null
                        ? '—'
                        : $record->price_min.($record->price_max !== null && $record->price_max != $record->price_min ? ' – '.$record->price_max : '').' '.$record->currency)
                    ->sortable(),

                TextColumn::make('moq')->label(__('admin.products.fields.moq'))->toggleable(),

                TextColumn::make('views')->label(__('admin.common.views'))->sortable()->toggleable(),

                TextColumn::make('updated_at')
                    ->label(__('admin.common.updated_at'))
                    ->dateTime('Y-m-d H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.common.status'))
                    ->options(__('admin.products.status_options')),
                SelectFilter::make('category')
                    ->label(__('admin.products.fields.category'))
                    ->relationship('category', 'slug'),
            ])
            ->recordActions([
                ReplicateAction::make()
                    ->label(__('admin.products.replicate'))
                    ->mutateRecordDataUsing(function (array $data): array {
                        // slug 追加 -copy-{4 位随机}（预留长度避免超出 255）
                        $suffix = '-copy-'.strtolower(Str::random(4));
                        $data['slug'] = substr((string) ($data['slug'] ?? ''), 0, 255 - strlen($suffix)).$suffix;
                        $data['status'] = 'draft';

                        // name 的各语言值末尾加 “ (Copy)”（模型 cast 后为 locale => value 数组）
                        $name = $data['name'] ?? null;

                        if (is_string($name) && $name !== '') {
                            $decoded = json_decode($name, true);
                            $name = is_array($decoded) ? $decoded : ['en' => $name];
                        }

                        if (is_array($name)) {
                            $data['name'] = array_map(
                                fn ($value) => is_string($value) && $value !== '' ? $value.' (Copy)' : $value,
                                $name,
                            );
                        }

                        return $data;
                    })
                    ->after(function (Model $record, Model $replica): void {
                        // 源产品图片附件逐条复制：新行新 id，同 path/disk/sort_order/alt
                        foreach ($record->images as $image) {
                            Attachment::create([
                                'attachable_type' => $replica->getMorphClass(),
                                'attachable_id' => $replica->getKey(),
                                'disk' => $image->disk,
                                'path' => $image->path,
                                'original_name' => $image->original_name,
                                'mime' => $image->mime,
                                'size' => $image->size,
                                'alt' => $image->getTranslations('alt'),
                                'sort_order' => $image->sort_order,
                            ]);
                        }
                    }),

                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),

                    BulkAction::make('set_status')
                        ->label(__('admin.products.bulk.set_status'))
                        ->icon('heroicon-o-tag')
                        ->modalHeading(__('admin.products.bulk.set_status'))
                        ->modalSubmitActionLabel(__('admin.products.bulk.set_status'))
                        ->schema([
                            Select::make('status')
                                ->label(__('admin.common.status'))
                                ->options(__('admin.products.status_options'))
                                ->required(),
                        ])
                        ->action(function (array $data, Collection $records): void {
                            $records->each->update(['status' => $data['status']]);

                            Notification::make()
                                ->title(__('admin.products.bulk.set_status'))
                                ->success()
                                ->body(__('admin.products.bulk.set_status_done', ['count' => $records->count()]))
                                ->send();
                        }),

                    BulkAction::make('move_category')
                        ->label(__('admin.products.bulk.move_category'))
                        ->icon('heroicon-o-folder-arrow-down')
                        ->modalHeading(__('admin.products.bulk.move_category'))
                        ->modalSubmitActionLabel(__('admin.products.bulk.move_category'))
                        ->schema([
                            Select::make('category_id')
                                ->label(__('admin.products.fields.category'))
                                ->options(fn (): array => ProductForm::categoryOptions())
                                ->searchable()
                                ->preload()
                                ->required(),
                        ])
                        ->action(function (array $data, Collection $records): void {
                            $records->each->update(['category_id' => (int) $data['category_id']]);

                            Notification::make()
                                ->title(__('admin.products.bulk.move_category'))
                                ->success()
                                ->body(__('admin.products.bulk.move_category_done', ['count' => $records->count()]))
                                ->send();
                        }),
                ]),
            ]);
    }
}
