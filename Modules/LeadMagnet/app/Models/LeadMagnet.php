<?php

namespace Modules\LeadMagnet\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

/**
 * 资料磁铁：slug 定位落地页，title/description 多语言，
 * 文件存 local private 盘（不可直链，只能通过签名路由下载）。
 */
class LeadMagnet extends Model
{
    use HasTranslations;

    protected $fillable = ['slug', 'title', 'description', 'file_path', 'active'];

    public array $translatable = ['title', 'description'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function downloads()
    {
        return $this->hasMany(MagnetDownload::class, 'magnet_id');
    }

    public function scopeActive($query)
    {
        return $query->where('active', true);
    }
}
