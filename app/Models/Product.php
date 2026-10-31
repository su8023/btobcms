<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

class Product extends Model
{
    use HasTranslations;

    protected $fillable = [
        'site_id', 'category_id', 'slug', 'sku', 'status', 'attrs',
        'price_min', 'price_max', 'currency', 'moq', 'unit',
        'sort_order', 'name', 'summary', 'description',
    ];

    public array $translatable = ['name', 'summary', 'description'];

    protected function casts(): array
    {
        return [
            'attrs' => 'array',
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
}
