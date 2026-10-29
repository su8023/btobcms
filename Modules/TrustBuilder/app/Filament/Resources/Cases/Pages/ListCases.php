<?php

namespace Modules\TrustBuilder\Filament\Resources\Cases\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Modules\TrustBuilder\Filament\Resources\CaseResource;

class ListCases extends ListRecords
{
    protected static string $resource = CaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
