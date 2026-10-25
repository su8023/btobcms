<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

class SeoMeta extends Model
{
    use HasTranslations;

    /** 迁移中的表名为 seo_meta（Laravel 复数约定会推成 seo_metas，显式指定） */
    protected $table = 'seo_meta';

    protected $fillable = [
        'metable_type', 'metable_id', 'title', 'description',
        'keywords', 'canonical', 'robots', 'og',
    ];

    public array $translatable = ['title', 'description', 'keywords'];

    protected function casts(): array
    {
        return [
            'og' => 'array',
        ];
    }

    public function metable()
    {
        return $this->morphTo();
    }
}
