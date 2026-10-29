<?php

namespace Modules\TrustBuilder\Filament\Resources\Cases\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Modules\TrustBuilder\Filament\Resources\CaseResource;

class EditCase extends EditRecord
{
    protected static string $resource = CaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
