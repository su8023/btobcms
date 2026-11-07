<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

class Product extends Model
{
    use HasTranslations;

    protected $fillable = [
        'site_id', 'category_id', 'slug', 'sku', 'status', 'specs',
        'price_min', 'price_max', 'currency', 'moq', 'unit',
        'price_range', 'certification', 'lead_time', 'aql',
        'core_keywords', 'hot_trending_keywords',
        'sort_order', 'name', 'summary', 'description',
    ];

    public array $translatable = ['name', 'summary', 'description'];

    protected function casts(): array
    {
        return [
            'specs' => 'array',
            'core_keywords' => 'array',
            'hot_trending_keywords' => 'array',
            'price_min' => 'decimal:2',
            'price_max' => 'decimal:2',
        ];
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    protected static function booted(): void
    {
        // 产品上下架 / 换分类都会改变分类子树的产品数
        static::saved(fn () => Category::flushCountCache());
        static::deleted(fn () => Category::flushCountCache());
    }

    public function images()
    {
        return $this->morphMany(Attachment::class, 'attachable')->orderBy('sort_order');
    }

    public function seoMeta()
    {
        return $this->morphOne(SeoMeta::class, 'metable');
    }

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    /**
     * 热搜词 chips：按类目顺序取每个类目前 N 个热搜词，链接到该类目的代表产品。
     *
     * 同一类目的多个产品共用同一批热搜词，所以词在类目内去重后，
     * 再轮转分配给该类目的各个产品，保证每个词都落到一个真实可点的产品上。
     *
     * @return array<int, array{label: string, url: string}>
     */
    public static function hotKeywordChips(int $perCategory = 1, ?int $categoryId = null): array
    {
        $grouped = static::published()
            ->with('category')
            ->when($categoryId, fn ($query) => $query->where('category_id', $categoryId))
            ->orderBy('id')
            ->get()
            ->groupBy('category_id')
            ->sortBy(fn ($group) => $group->first()->category?->sort_order ?? 0);

        $chips = [];
        $seen = [];

        foreach ($grouped as $group) {
            $products = $group->values();
            $terms = [];

            foreach ($products as $product) {
                foreach ((array) $product->hot_trending_keywords as $term) {
                    $term = trim((string) $term);

                    if ($term === '' || isset($seen[$term]) || isset($terms[$term])) {
                        continue;
                    }

                    $seen[$term] = true;
                    $terms[$term] = true;
                }
            }

            $terms = array_keys($terms);

            foreach (array_slice($terms, 0, $perCategory) as $index => $term) {
                $product = $products[$index % $products->count()];

                $chips[] = [
                    'label' => $term,
                    'url' => localized_route('products.show', ['slug' => $product->slug]),
                ];
            }
        }

        return $chips;
    }
}
