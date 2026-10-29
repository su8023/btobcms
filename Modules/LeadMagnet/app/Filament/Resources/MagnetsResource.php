<?php

namespace Modules\LeadMagnet\Filament\Resources;

use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Modules\LeadMagnet\Filament\Resources\Magnets\Pages\CreateMagnet;
use Modules\LeadMagnet\Filament\Resources\Magnets\Pages\EditMagnet;
use Modules\LeadMagnet\Filament\Resources\Magnets\Pages\ListMagnets;
use Modules\LeadMagnet\Filament\Resources\Magnets\Schemas\MagnetForm;
use Modules\LeadMagnet\Filament\Resources\Magnets\Tables\MagnetsTable;
use Modules\LeadMagnet\Models\LeadMagnet;

/**
 * 资料磁铁后台管理。
 *
 * 注意：资源类文件须位于 Resources/ 顶层（插件资源约定式发现只扫描
 * Resources/*Resource.php），Pages/Schemas/Tables 分层放 Magnets/ 子目录。
 */
class MagnetsResource extends Resource
{
    protected static ?string $model = LeadMagnet::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentArrowDown;

    protected static ?int $navigationSort = 31;

    protected static ?string $recordTitleAttribute = 'slug';

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return __('admin.groups.growth');
    }

    public static function getNavigationLabel(): string
    {
        return '资料磁铁';
    }

    public static function getModelLabel(): string
    {
        return '资料磁铁';
    }

    public static function getPluralModelLabel(): string
    {
        return '资料磁铁';
    }

    public static function form(Schema $schema): Schema
    {
        return MagnetForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MagnetsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMagnets::route('/'),
            'create' => CreateMagnet::route('/create'),
            'edit' => EditMagnet::route('/{record}/edit'),
        ];
    }
}
