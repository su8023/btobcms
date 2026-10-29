<?php

namespace Modules\LeadMagnet\Filament\Resources\Magnets\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\LeadMagnet\Filament\Resources\MagnetsResource;

class ListMagnets extends ListRecords
{
    protected static string $resource = MagnetsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
