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

    protected function getHeaderActions(): array
    {
        // 插件提供的编辑器动作（AI 填充/SEO 体检等，约定式发现）
        return \App\Support\PluginPostActions::forPage('create');
    }
}
