<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'import:products', description: 'Import or update products from a CSV file (upsert by slug)')]
class ImportProductsCommand extends Command
{
    /**
     * CSV 支持的列（首行为表头，未知列忽略；大小写与前后空白不敏感）。
     */
    protected const CSV_COLUMNS = [
        'slug', 'category_slug', 'sku', 'status', 'currency',
        'price_min', 'price_max', 'moq', 'unit',
        'name_en', 'name_es', 'name_ar', 'name_ru',
        'summary_en', 'summary_es', 'summary_ar', 'summary_ru',
    ];

    /** 直接写库的普通列（非多语言） */
    protected const PLAIN_COLUMNS = ['sku', 'currency', 'price_min', 'price_max', 'moq', 'unit'];

    protected const STATUSES = ['draft', 'published'];

    protected $signature = 'import:products
        {file : CSV 文件路径（首行为表头，UTF-8）}
        {--dry-run : 只校验不写库}';

    protected $description = 'Import or update products from a CSV file (upsert by slug)';

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        $dryRun = (bool) $this->option('dry-run');

        if (! is_file($path) || ! is_readable($path)) {
            $this->error(__('admin.products.import.file_not_found', ['path' => $path]));

            return self::FAILURE;
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            $this->error(__('admin.products.import.file_not_found', ['path' => $path]));

            return self::FAILURE;
        }

        try {
            return $this->import($handle, $dryRun);
        } finally {
            fclose($handle);
        }
    }

    /**
     * @param  resource  $handle
     */
    protected function import($handle, bool $dryRun): int
    {
        // 首行为表头；剥 UTF-8 BOM
        $header = fgetcsv($handle);

        if ($header === false) {
            $this->error(__('admin.products.import.file_empty'));

            return self::FAILURE;
        }

        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) ($header[0] ?? ''));

        /** @var array<int, string> $columnMap CSV 列下标 => 规范列名 */
        $columnMap = [];
        $unknownColumns = [];

        foreach ($header as $index => $cell) {
            $name = strtolower(trim((string) $cell));

            if ($name === '') {
                continue;
            }

            if (in_array($name, self::CSV_COLUMNS, true)) {
                $columnMap[$index] = $name;
            } else {
                $unknownColumns[$name] = true;
            }
        }

        if ($columnMap === []) {
            $this->error(__('admin.products.import.no_valid_columns'));

            return self::FAILURE;
        }

        if ($unknownColumns !== []) {
            $this->line(__('admin.products.import.ignored_columns', ['columns' => implode(', ', array_keys($unknownColumns))]));
        }

        // 分类按 slug 查找，找不到的行跳过并计数
        $categories = Category::query()->pluck('id', 'slug');

        $created = 0;
        $updated = 0;
        $skipped = 0;
        $rowNumber = 1;

        while (($raw = fgetcsv($handle)) !== false) {
            $rowNumber++;

            // 完全空白的行直接忽略
            if (trim(implode('', array_map(fn ($cell) => (string) $cell, $raw))) === '') {
                continue;
            }

            $row = [];

            foreach ($columnMap as $index => $column) {
                $row[$column] = isset($raw[$index]) ? trim((string) $raw[$index]) : '';
            }

            // 必填：slug + category_slug + name_en
            if (($row['slug'] ?? '') === '' || ($row['category_slug'] ?? '') === '' || ($row['name_en'] ?? '') === '') {
                $skipped++;
                $this->skipRow($rowNumber, __('admin.products.import.reason_required'));

                continue;
            }

            if (! $categories->has($row['category_slug'])) {
                $skipped++;
                $this->skipRow($rowNumber, __('admin.products.import.reason_category', ['slug' => $row['category_slug']]));

                continue;
            }

            $status = $row['status'] ?? '';

            if ($status !== '' && ! in_array($status, self::STATUSES, true)) {
                $skipped++;
                $this->skipRow($rowNumber, __('admin.products.import.reason_status', ['status' => $status]));

                continue;
            }

            $invalidNumericColumn = null;

            foreach (['price_min', 'price_max'] as $column) {
                if (($row[$column] ?? '') !== '' && ! is_numeric($row[$column])) {
                    $invalidNumericColumn = $column;

                    break;
                }
            }

            if ($invalidNumericColumn !== null) {
                $skipped++;
                $this->skipRow($rowNumber, __('admin.products.import.reason_number', ['column' => $invalidNumericColumn]));

                continue;
            }

            // 组装普通列（仅给出的非空列生效）
            $attributes = [];

            foreach (self::PLAIN_COLUMNS as $column) {
                if (($row[$column] ?? '') !== '') {
                    $attributes[$column] = $row[$column];
                }
            }

            // 组装多语言列（仅给出的非空语言生效）
            $translations = ['name' => [], 'summary' => []];

            foreach (['name', 'summary'] as $field) {
                foreach (site_locales() as $locale) {
                    $value = $row[$field.'_'.$locale] ?? '';

                    if ($value !== '') {
                        $translations[$field][$locale] = $value;
                    }
                }
            }

            /** @var Product|null $product */
            $product = Product::query()->where('slug', $row['slug'])->first();

            if ($product === null) {
                $payload = array_merge($attributes, [
                    'category_id' => $categories->get($row['category_slug']),
                    'slug' => $row['slug'],
                    'status' => $status !== '' ? $status : 'draft',
                    'name' => $translations['name'],
                ]);

                if ($translations['summary'] !== []) {
                    $payload['summary'] = $translations['summary'];
                }

                if (! $dryRun) {
                    Product::create($payload);
                }

                $created++;

                continue;
            }

            // 已存在：只更新给出的非空列
            foreach ($attributes as $column => $value) {
                $product->{$column} = $value;
            }

            if ($status !== '') {
                $product->status = $status;
            }

            $product->category_id = $categories->get($row['category_slug']);

            foreach (['name', 'summary'] as $field) {
                if ($translations[$field] !== []) {
                    $product->{$field} = array_merge(
                        $product->getTranslations($field),
                        $translations[$field],
                    );
                }
            }

            if (! $dryRun) {
                $product->save();
            }

            $updated++;
        }

        $this->newLine();
        $this->info(__('admin.products.import.stats', [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
        ]));

        if ($dryRun) {
            $this->warn(__('admin.products.import.dry_run'));
        }

        return self::SUCCESS;
    }

    protected function skipRow(int $rowNumber, string $reason): void
    {
        $this->line(__('admin.products.import.row_skipped', ['row' => $rowNumber, 'reason' => $reason]));
    }
}
