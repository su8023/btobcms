<?php

namespace Modules\LinkWeaver\Filament\Resources\LinkTerms\Pages;

use Filament\Resources\Pages\EditRecord;
use Modules\LinkWeaver\Filament\Resources\LinkTermResource;

class EditLinkTerm extends EditRecord
{
    protected static string $resource = LinkTermResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (($data['locale'] ?? '') === '') {
            $data['locale'] = null;
        }

        return $data;
    }
}
