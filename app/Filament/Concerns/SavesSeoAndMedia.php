<?php

namespace App\Filament\Concerns;

use App\Models\Attachment;
use App\Models\SeoMeta;
use Illuminate\Database\Eloquent\Model;

/**
 * 供 Filament Create/Edit 页面复用：
 * - 多语言字段（name.en 点号路径）的填充/清洗
 * - SEO 覆盖字段（seo.*）的读取与保存
 * - 产品多图（uploaded_images）与 Attachment 表的同步
 * - 既有图片的 ALT 多语言编辑（alt_fields，按 image_id 写回 Attachment.alt）
 */
trait SavesSeoAndMedia
{
    /** 产品页覆写为 true 以启用图片同步 */
    protected function syncMedia(): bool
    {
        return false;
    }

    protected array $extractedMeta = [];

    protected function extractExtra(array &$data): array
    {
        $extra = [
            'seo' => $data['seo'] ?? null,
            'media' => $this->syncMedia() ? ($data['uploaded_images'] ?? []) : null,
            'alt_fields' => $this->syncMedia() ? ($data['alt_fields'] ?? null) : null,
        ];

        unset($data['seo'], $data['uploaded_images'], $data['alt_fields']);

        // 空字符串不写入翻译 JSON
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = array_filter($value, fn ($v) => $v !== null && $v !== '');
            }
        }

        return $extra;
    }

    protected function handleRecordCreation(array $data): Model
    {
        $extra = $this->extractExtra($data);
        $this->extractedMeta = $extra;

        $record = parent::handleRecordCreation($data);

        $this->persistExtra($record, $extra);

        return $record;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $extra = $this->extractExtra($data);
        $this->extractedMeta = $extra;

        $updated = parent::handleRecordUpdate($record, $data);

        $this->persistExtra($updated, $extra);

        return $updated;
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $record = $this->getRecord();

        // 多语言字段：用完整翻译数组替换当前语言字符串
        if (property_exists($record, 'translatable') && is_array($record->translatable)) {
            foreach ($record->translatable as $field) {
                $data[$field] = $record->getTranslations($field);
            }
        }

        if (method_exists($record, 'seoMeta')) {
            $meta = $record->seoMeta;
            $data['seo'] = [
                'title' => $meta?->getTranslations('title') ?? [],
                'description' => $meta?->getTranslations('description') ?? [],
                'keywords' => $meta?->getTranslations('keywords') ?? [],
            ];
        }

        if ($this->syncMedia() && method_exists($record, 'images')) {
            $data['uploaded_images'] = $record->images->pluck('path')->all();

            // 既有图片的 ALT 编辑项：每图一条，新上传的图片本次尚无条目，下次编辑时出现
            $data['alt_fields'] = $record->images->map(function (Attachment $image): array {
                $alt = $image->getTranslations('alt');

                $item = [
                    'image_id' => $image->getKey(),
                    'path' => $image->path,
                ];

                foreach (site_locales() as $locale) {
                    $item['alt_'.$locale] = $alt[$locale] ?? '';
                }

                return $item;
            })->values()->all();
        }

        return $data;
    }

    protected function persistExtra(Model $record, array $extra): void
    {
        if (is_array($extra['seo'] ?? null) && method_exists($record, 'seoMeta')) {
            $values = [
                'title' => array_filter((array) ($extra['seo']['title'] ?? [])),
                'description' => array_filter((array) ($extra['seo']['description'] ?? [])),
                'keywords' => array_filter((array) ($extra['seo']['keywords'] ?? [])),
            ];

            $hasValues = collect($values)->flatten()->isNotEmpty();

            if ($hasValues || $record->seoMeta()->exists()) {
                $record->seoMeta()->updateOrCreate([], $values);
            }
        }

        if ($this->syncMedia() && is_array($extra['media'] ?? null) && method_exists($record, 'images')) {
            $this->syncImages($record, $extra['media']);
        }

        if ($this->syncMedia() && is_array($extra['alt_fields'] ?? null) && method_exists($record, 'images')) {
            $this->persistImageAlt($record, $extra['alt_fields']);
        }
    }

    /**
     * 按 image_id 把 alt_fields（alt_en / alt_es …）写回对应 Attachment 的 alt JSON。
     * 只更新属于当前记录的附件，避免 image_id 被篡改后越权修改其他记录的图片。
     */
    protected function persistImageAlt(Model $record, array $altFields): void
    {
        $itemsById = collect($altFields)
            ->filter(fn ($item) => is_array($item) && ! empty($item['image_id']))
            ->keyBy(fn (array $item) => (int) $item['image_id']);

        if ($itemsById->isEmpty()) {
            return;
        }

        $record->images()
            ->whereKey($itemsById->keys()->all())
            ->get()
            ->each(function (Attachment $image) use ($itemsById): void {
                $item = $itemsById->get($image->getKey());

                if ($item === null) {
                    return;
                }

                $alt = [];

                foreach (site_locales() as $locale) {
                    $value = trim((string) ($item['alt_'.$locale] ?? ''));

                    if ($value !== '') {
                        $alt[$locale] = $value;
                    }
                }

                // 空值语言不写入 alt JSON
                $image->update(['alt' => $alt]);
            });
    }

    protected function syncImages(Model $record, array $paths): void
    {
        $paths = array_values($paths);
        $existing = $record->images->keyBy('path');

        foreach ($paths as $index => $path) {
            if ($existing->has($path)) {
                $existing[$path]->update(['sort_order' => $index]);
                $existing->forget($path);
            } else {
                Attachment::create([
                    'attachable_type' => $record->getMorphClass(),
                    'attachable_id' => $record->getKey(),
                    'disk' => 'public',
                    'path' => $path,
                    'sort_order' => $index,
                ]);
            }
        }

        // 表单里移除的图片：删除记录（磁盘文件保留，避免误删共享文件）
        $existing->each->delete();
    }
}
