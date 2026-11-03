<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

class Post extends Model
{
    use HasTranslations;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_REVIEW = 'review';

    public const STATUS_PUBLISHED = 'published';

    protected $fillable = [
        'site_id', 'type', 'slug', 'status', 'author_id', 'is_ai_generated',
        'cover', 'published_at', 'views', 'title', 'excerpt', 'content',
    ];

    public array $translatable = ['title', 'excerpt', 'content'];

    protected static function booted(): void
    {
        // 状态为「已发布」但发布时间为空时自动补 now()——所有写入路径
        // （后台保存、发布动作、AI 命令）同一兜底。缺这一步时 published()
        // 过滤掉 published_at 为空的行，文章"已发布"却全站 404（线上实锤）。
        // 已有值不覆盖：改回草稿再发布不刷新首次发布时间。
        static::saving(static function (self $post): void {
            if ($post->status === self::STATUS_PUBLISHED && $post->published_at === null) {
                $post->published_at = now();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'is_ai_generated' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function seoMeta()
    {
        return $this->morphOne(SeoMeta::class, 'metable');
    }

    public function scopePublished($query)
    {
        return $query->where('status', self::STATUS_PUBLISHED)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }
}
