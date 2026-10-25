<?php

namespace App\Filament\Resources\AttrSets;

use App\Filament\Resources\AttrSets\Pages\CreateAttrSet;
use App\Filament\Resources\AttrSets\Pages\EditAttrSet;
use App\Filament\Resources\AttrSets\Pages\ListAttrSets;
use App\Filament\Resources\AttrSets\Schemas\AttrSetForm;
use App\Filament\Resources\AttrSets\Tables\AttrSetsTable;
use App\Models\AttrSet;
use BackedEnum;
use UnitEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class AttrSetResource extends Resource
{
    protected static ?string $model = AttrSet::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?int $navigationSort = 12;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationGroup(): string | UnitEnum | null
    {
        return __('admin.groups.catalog');
    }

    public static function getModelLabel(): string
    {
        return __('admin.attr_sets.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.attr_sets.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return AttrSetForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AttrSetsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAttrSets::route('/'),
            'create' => CreateAttrSet::route('/create'),
            'edit' => EditAttrSet::route('/{record}/edit'),
        ];
    }
}
