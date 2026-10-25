<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Concerns\SavesSeoAndMedia;
use Filament\Resources\Pages\CreateRecord;

class CreateProduct extends CreateRecord
{
    protected static string $resource = ProductResource::class;

    use SavesSeoAndMedia;

    protected function syncMedia(): bool
    {
        return true;
    }
}
