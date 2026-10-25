<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Spatie\Translatable\HasTranslations;

class Category extends Model
{
    use HasTranslations;

    protected $fillable = [
        'site_id', 'parent_id', 'path', 'slug', 'attr_set_id',
        'name', 'description', 'sort_order', 'status',
    ];

    public array $translatable = ['name', 'description'];

    protected function casts(): array
    {
        return [
            'status' => 'boolean',
        ];
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order');
    }

    public function attrSet()
    {
        return $this->belongsTo(AttrSet::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function seoMeta()
    {
        return $this->morphOne(SeoMeta::class, 'metable');
    }

    /** 物化路径子树：1/45/ 下的所有后代 */
    public function scopeDescendantsOf($query, self $category)
    {
        return $query->where('path', 'like', $category->path.'/%');
    }

    /** 创建/移动后重建物化路径，子树需递归调用 */
    public function rebuildPath(): void
    {
        $this->path = $this->parent ? $this->parent->path.'/'.$this->id : (string) $this->id;
        $this->saveQuietly();
    }

    /**
     * 子树产品数（含本分类与全部后代分类下已发布的产品）。
     * 基于物化路径首段聚合，一次查询覆盖全部分类。
     */
    public function subtreeProductsCount(): int
    {
        $counts = Cache::remember('category-subtree-counts', 3600, function () {
            $counts = [];
            Product::query()
                ->where('products.status', 'published')
                ->join('categories', 'products.category_id', '=', 'categories.id')
                ->pluck('categories.path')
                ->each(function (string $path) use (&$counts) {
                    $root = (int) explode('/', $path)[0];
                    $counts[$root] = ($counts[$root] ?? 0) + 1;
                });

            return $counts;
        });

        return $counts[$this->id] ?? 0;
    }

    /** 子分类/产品变动后清除计数缓存 */
    public function invalidateCountCache(): void
    {
        Cache::forget('category-subtree-counts');
    }
}
