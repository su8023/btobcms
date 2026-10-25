<?php

namespace App\Filament\Resources\Posts\Pages;

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

    use SavesSeoAndMedia;

    protected function getHeaderActions(): array
    {
        return [
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
                ->visible(fn () => $this->record->status !== 'published')
                ->action(function (): void {
                    $this->record->update([
                        'status' => 'published',
                        'published_at' => $this->record->published_at ?? now(),
                    ]);

                    Notification::make()
                        ->title(__('admin.posts.published'))
                        ->success()
                        ->send();
                }),

            DeleteAction::make(),
        ];
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
                        ->whereRaw('lower(name) like ?', ['%'.mb_strtolower($keyword).'%'])
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
