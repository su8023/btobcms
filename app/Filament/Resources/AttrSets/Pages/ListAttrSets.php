<?php

namespace App\Filament\Resources\AttrSets\Pages;

use App\Filament\Resources\AttrSets\AttrSetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAttrSets extends ListRecords
{
    protected static string $resource = AttrSetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
