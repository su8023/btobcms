<?php

namespace App\Filament\Resources\AttrSets\Pages;

use App\Filament\Resources\AttrSets\AttrSetResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAttrSet extends EditRecord
{
    protected static string $resource = AttrSetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
