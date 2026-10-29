<?php

namespace Modules\LeadMagnet\Filament\Resources\Magnets\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\LeadMagnet\Filament\Resources\MagnetsResource;

class EditMagnet extends EditRecord
{
    protected static string $resource = MagnetsResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
