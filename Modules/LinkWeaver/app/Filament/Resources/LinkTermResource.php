<?php

namespace Modules\LinkWeaver\Filament\Resources;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Actions\Action;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\LinkWeaver\Filament\Resources\LinkTerms\Pages\CreateLinkTerm;
use Modules\LinkWeaver\Filament\Resources\LinkTerms\Pages\EditLinkTerm;
use Modules\LinkWeaver\Filament\Resources\LinkTerms\Pages\ListLinkTerms;
use Modules\LinkWeaver\Models\LinkTerm;
use Modules\LinkWeaver\Services\Weaver;

class LinkTermResource extends Resource
{
    protected static ?string $model = LinkTerm::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static ?int $navigationSort = 31;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return __('admin.groups.growth');
    }

    public static function getNavigationLabel(): string
    {
        return '内链词典';
    }

    public static function getPluralModelLabel(): string
    {
        return '内链词典';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('keyword')
                ->label('关键词')
                ->helperText('文章正文中出现该词时自动织为内链（英文不区分大小写；建议 3 词以上的具体词，避免过短误链）')
                ->required()
                ->minLength(3)
                ->maxLength(191),

            TextInput::make('target')
                ->label('目标链接')
                ->helperText('站内相对地址，如 /product/gate-valve 或 /industries/chemical')
                ->required()
                ->maxLength(500)
                ->rules(['starts_with:/']),

            TextInput::make('title')
                ->label('链接提示（可选）')
                ->maxLength(191),

            Select::make('locale')
                ->label('生效语言')
                ->options(array_merge(
                    ['' => '全部语言'],
                    collect(config('site.locales', []))->mapWithKeys(fn ($l) => [$l => $l])->all(),
                ))
                ->default('')
                ->helperText('按语言维护不同关键词（如西语词只在西语正文织链）'),

            Toggle::make('enabled')
                ->label('启用')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('keyword')->label('关键词')->searchable()->sortable(),
                TextColumn::make('target')->label('目标链接')->limit(48)->copyable(),
                TextColumn::make('locale')->label('语言')->badge()->placeholder('全部'),
                TextColumn::make('appliedLinks_count')->counts('appliedLinks')->label('已织入')->sortable(),
                IconColumn::make('enabled')->label('启用')->boolean(),
                TextColumn::make('updated_at')->label('更新于')->since(),
            ])
            ->filters([
                //
            ])
            ->headerActions([
                Action::make('reweave')
                    ->label('全量回织')
                    ->icon(Heroicon::OutlinedSparkles)
                    ->action(function (Action $action): void {
                        [$posts, $links] = app(Weaver::class)->reweaveAll();
                        $action
                            ->success()
                            ->successNotificationTitle(
                                $links > 0
                                    ? "回织完成：{$posts} 篇文章新增 {$links} 条内链"
                                    : '回织完成：没有新的可织关键词（全部已织或正文未命中）',
                            );
                    }),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLinkTerms::route('/'),
            'create' => CreateLinkTerm::route('/create'),
            'edit' => EditLinkTerm::route('/{record}/edit'),
        ];
    }
}
