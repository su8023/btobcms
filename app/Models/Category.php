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
     *
     * 物化路径 path 由祖先 id 拼成（见 rebuildPath），因此 path 的每一段
     * 都是一个祖先分类 id：产品所在分类 path="1/2" 的这棵树，对祖先 1 与祖先 2
     * 都要计数。
     *
     * 旧实现只按「首段（根 id）」计数，却用 $this->id 去查，
     * 于是所有非根分类永远拿到 0（实测 valves/pumps/steel-pipes 各有 1 个产品却显示 0）。
     */
    public function subtreeProductsCount(): int
    {
        // 缓存键带版本号：计数口径从"按根分类聚合"改成"按各级祖先聚合"，
        // 语义变了但键没变的话，老缓存会继续返回错误结果直到过期。
        $counts = Cache::remember('category-subtree-counts-v2', 3600, function () {
            $counts = [];

            Product::query()
                ->where('products.status', 'published')
                ->join('categories', 'products.category_id', '=', 'categories.id')
                ->pluck('categories.path')
                ->each(function (string $path) use (&$counts) {
                    // 逐段（= 各级祖先 id）累加
                    foreach (explode('/', trim($path, '/')) as $segment) {
                        if ($segment === '') {
                            continue;
                        }

                        $id = (int) $segment;
                        $counts[$id] = ($counts[$id] ?? 0) + 1;
                    }
                });

            return $counts;
        });

        return $counts[$this->id] ?? 0;
    }

    /** 子分类/产品变动后清除计数缓存 */
    public function invalidateCountCache(): void
    {
        self::flushCountCache();
    }

    /** 清除子树产品数缓存（产品/分类增删改后调用） */
    public static function flushCountCache(): void
    {
        Cache::forget('category-subtree-counts');
        Cache::forget('category-subtree-counts-v2');
    }

    protected static function booted(): void
    {
        // 分类自身的增删改会改变子树归属
        static::saved(fn () => self::flushCountCache());
        static::deleted(fn () => self::flushCountCache());
    }
}
