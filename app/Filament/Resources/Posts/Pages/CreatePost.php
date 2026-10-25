<?php

namespace App\Filament\Resources\Posts\Pages;

use App\Events\PostPublished;
use App\Filament\Resources\Posts\PostResource;
use App\Filament\Concerns\SavesSeoAndMedia;
use Filament\Resources\Pages\CreateRecord;

class CreatePost extends CreateRecord
{
    protected static string $resource = PostResource::class;

    use SavesSeoAndMedia;

    protected function afterCreate(): void
    {
        if ((string) $this->getRecord()->status === 'published') {
            PostPublished::dispatch($this->getRecord());
        }
    }
}
