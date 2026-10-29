<?php

namespace Modules\TrustBuilder\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Spatie\Translatable\HasTranslations;

/**
 * 客户案例：slug 定位详情页，title/body 多语言，
 * cover/images 存 public 盘相对路径；published=false 前台不可见。
 */
class TrustCase extends Model
{
    use HasTranslations;

    protected $table = 'trust_cases';

    protected $fillable = [
        'slug', 'customer_name', 'industry', 'country',
        'title', 'body', 'cover', 'images', 'published', 'sort',
    ];

    public array $translatable = ['title', 'body'];

    protected function casts(): array
    {
        return [
            'published' => 'boolean',
            'images' => 'array',
        ];
    }

    /**
     * 前台可见范围：仅已发布。
     */
    public function scopePublished($query)
    {
        return $query->where('published', true);
    }

    /**
     * 展示顺序：sort 升序，同序号按创建先后稳定排序。
     */
    public function scopeSorted($query)
    {
        return $query->orderBy('sort')->orderBy('id');
    }

    /**
     * 封面图完整 URL（public 盘）；未设置返回空串。
     */
    public function coverUrl(): string
    {
        return $this->cover ? (string) Storage::disk('public')->url($this->cover) : '';
    }

    /**
     * 图集完整 URL 列表（public 盘）。
     */
    public function imageUrls(): array
    {
        return array_map(
            fn (string $path): string => (string) Storage::disk('public')->url($path),
            (array) ($this->images ?? []),
        );
    }

    /**
     * 详情页 meta 行：customer · industry · country（过滤空值）。
     */
    public function metaLine(): string
    {
        return collect([$this->customer_name, $this->industry, $this->country])
            ->filter()
            ->implode(' · ');
    }
}
