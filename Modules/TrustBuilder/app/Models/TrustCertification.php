<?php

namespace Modules\TrustBuilder\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Spatie\Translatable\HasTranslations;

/**
 * 认证证书：name 多语言（ISO 9001:2015 …），其余为元数据。
 * 仅在全站信任条展示证书名徽章，无前台详情页。
 */
class TrustCertification extends Model
{
    use HasTranslations;

    protected $table = 'trust_certifications';

    protected $fillable = [
        'name', 'issuer', 'cert_no', 'issued_at', 'expires_at', 'image', 'sort',
    ];

    public array $translatable = ['name'];

    protected function casts(): array
    {
        return [
            'issued_at' => 'date',
            'expires_at' => 'date',
        ];
    }

    /**
     * 展示顺序：sort 升序，同序号按创建先后稳定排序。
     */
    public function scopeSorted($query)
    {
        return $query->orderBy('sort')->orderBy('id');
    }

    /**
     * 证书图完整 URL（public 盘）；未设置返回空串。
     */
    public function imageUrl(): string
    {
        return $this->image ? (string) Storage::disk('public')->url($this->image) : '';
    }
}
