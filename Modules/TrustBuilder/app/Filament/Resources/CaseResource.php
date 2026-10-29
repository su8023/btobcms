<?php

namespace Modules\TrustBuilder\Filament\Resources;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Modules\TrustBuilder\Filament\Resources\Cases\Pages\CreateCase;
use Modules\TrustBuilder\Filament\Resources\Cases\Pages\EditCase;
use Modules\TrustBuilder\Filament\Resources\Cases\Pages\ListCases;
use Modules\TrustBuilder\Filament\Resources\Cases\Schemas\CaseForm;
use Modules\TrustBuilder\Filament\Resources\Cases\Tables\CasesTable;
use Modules\TrustBuilder\Models\TrustCase;

/**
 * 客户案例后台管理。
 *
 * 注意：资源类文件须位于 Resources/ 顶层（插件资源约定式发现只扫描
 * Resources/*Resource.php），Pages/Schemas/Tables 分层放 Cases/ 子目录。
 */
class CaseResource extends Resource
{
    protected static ?string $model = TrustCase::class;

    // URL 标识：/admin/cases（显式声明，避免类名推导歧义）
    protected static ?string $slug = 'cases';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTrophy;

    protected static ?int $navigationSort = 31;

    protected static ?string $recordTitleAttribute = 'slug';

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return __('admin.groups.growth');
    }

    public static function getNavigationLabel(): string
    {
        return __('trustbuilder.admin.cases');
    }

    public static function getModelLabel(): string
    {
        return __('trustbuilder.admin.case');
    }

    public static function getPluralModelLabel(): string
    {
        return __('trustbuilder.admin.cases');
    }

    public static function form(Schema $schema): Schema
    {
        return CaseForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CasesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCases::route('/'),
            'create' => CreateCase::route('/create'),
            'edit' => EditCase::route('/{record}/edit'),
        ];
    }
}
