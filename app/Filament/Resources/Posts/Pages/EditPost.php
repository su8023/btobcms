<?php

namespace App\Filament\Resources\Posts\Pages;

use App\Events\PostPublished;
use App\Filament\Resources\Posts\PostResource;
use App\Models\Product;
use App\Filament\Concerns\SavesSeoAndMedia;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Str;

class EditPost extends EditRecord
{
    protected static string $resource = PostResource::class;

    /** beforeSave 捕获的状态跃迁前值：syncOriginal 在 save 返回前执行，afterSave 里 getOriginal 已是新值 */
    protected ?string $statusBeforeSave = null;

    protected function beforeSave(): void
    {
        $this->statusBeforeSave = (string) $this->getRecord()->getOriginal('status');
    }

    protected function afterSave(): void
    {
        if ((string) $this->getRecord()->status === 'published'
            && $this->statusBeforeSave !== 'published') {
            PostPublished::dispatch($this->getRecord());
        }
        $this->statusBeforeSave = null;
    }

    use SavesSeoAndMedia;

    protected function getHeaderActions(): array
    {
        return array_merge(
            // 插件提供的编辑器动作（AI 填充/翻译/SEO 体检等，约定式发现）
            \App\Support\PluginPostActions::forPage('edit'),
            [
                Action::make('replaceLinks')
                    ->label(__('admin.posts.replace_links'))
                    ->icon('heroicon-o-link')
                    ->color('gray')
                    ->action(fn () => $this->replaceProductLinks()),

                Action::make('publish')
                    ->label(__('admin.posts.publish'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('确认发布？')
                    ->modalDescription('文章将立即出现在网站上（已翻译的语言同时上线）。发布后系统自动补全 SEO 信息并推送给搜索引擎，随时可以回来修改。')
                    ->modalSubmitActionLabel('立即发布')
                    ->visible(fn () => $this->record->status !== 'published')
                    ->action(function (): void {
                        // 直接 update 绕过页面 save 流程，跃迁需在此补发 PostPublished
                        $wasPublished = (string) $this->record->status === 'published';
                        $this->record->update([
                            'status' => 'published',
                            'published_at' => $this->record->published_at ?? now(),
                        ]);

                        if (! $wasPublished) {
                            PostPublished::dispatch($this->record);
                        }

                        Notification::make()
                            ->title(__('admin.posts.published'))
                            ->success()
                            ->send();
                    }),

                DeleteAction::make(),
            ],
        );
    }

    /**
     * 将各语言 content 中的 <!--PRODUCT_LINK:关键词--> 占位符
     * 替换为已发布产品的内链；每个关键词精确匹配 0 个或多个产品时跳过并汇报。
     */
    protected function replaceProductLinks(): void
    {
        $post = $this->record;
        $replaced = 0;
        $skipped = [];

        foreach (site_locales() as $locale) {
            $content = (string) $post->getTranslation('content', $locale, false);

            if ($content === '' || ! str_contains($content, '<!--PRODUCT_LINK:')) {
                continue;
            }

            $newContent = preg_replace_callback(
                '/<!--PRODUCT_LINK:(.+?)-->/',
                function (array $m) use (&$replaced, &$skipped): string {
                    $keyword = trim($m[1]);
                    $products = Product::published()
                        // name 是多语言 JSON 列：整列 LIKE 会跨语言误命中，
                        // 这里只在当前语言与英文两个键内匹配（后台编辑语言不定）
                        ->where(function ($query) use ($keyword) {
                            $needle = '%'.mb_strtolower($keyword).'%';

                            foreach (array_unique([app()->getLocale(), 'en']) as $locale) {
                                $query->orWhereRaw(
                                    'json_extract(name, ?) like ?',
                                    ['$."'.$locale.'"', $needle]
                                );
                            }
                        })
                        ->get();

                    if ($products->count() !== 1) {
                        $skipped[] = $keyword.' ('.($products->isEmpty()
                            ? __('admin.posts.link_no_match')
                            : __('admin.posts.link_ambiguous', ['count' => $products->count()])).')';

                        return $m[0];
                    }

                    $product = $products->first();
                    $replaced++;

                    return sprintf(
                        '<a href="%s">%s</a>',
                        e(localized_route('products.show', ['slug' => $product->slug])),
                        e($product->getTranslation('name', default_locale())),
                    );
                },
                $content,
            );

            if ($newContent !== $content) {
                $post->setTranslation('content', $locale, $newContent);
            }
        }

        $post->save();

        Notification::make()
            ->title(__('admin.posts.links_replaced'))
            ->body(
                __('admin.posts.links_replaced_body', ['count' => $replaced])
                .($skipped !== [] ? "\n".__('admin.posts.links_skipped').': '.implode('; ', array_slice(array_unique($skipped), 0, 6)) : '')
            )
            ->{$replaced > 0 ? 'success' : 'warning'}()
            ->persistent()
            ->send();
    }
}
