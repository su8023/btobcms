<?php

namespace App\Filament\Resources\Products\Pages;

use App\Events\ProductSaved;
use App\Filament\Resources\Products\ProductResource;
use App\Filament\Concerns\SavesSeoAndMedia;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function afterSave(): void
    {
        ProductSaved::dispatch($this->getRecord(), false);
    }

    use SavesSeoAndMedia;

    protected function syncMedia(): bool
    {
        return true;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
