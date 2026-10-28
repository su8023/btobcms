<?php

namespace Modules\LinkWeaver\Filament\Resources\LinkTerms\Pages;

use Filament\Resources\Pages\CreateRecord;
use Modules\LinkWeaver\Filament\Resources\LinkTermResource;

class CreateLinkTerm extends CreateRecord
{
    protected static string $resource = LinkTermResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['locale'] = $data['locale'] ?? '';
        if ($data['locale'] === '') {
            $data['locale'] = null;
        }

        return $data;
    }
}
