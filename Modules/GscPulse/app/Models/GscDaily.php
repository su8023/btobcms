<?php

namespace Modules\GscPulse\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * GSC 每日搜索表现流水（page × query × country × date 粒度）。
 * 写入仅经 GscFetcher 的 updateOrCreate（唯一键 fingerprint）。
 */
class GscDaily extends Model
{
    // 显式指定表名：避免 Laravel 按类名复数化成 gsc_dailies
    protected $table = 'gsc_daily';

    protected $fillable = [
        'site_url', 'date', 'page', 'query', 'country', 'clicks', 'impressions', 'position', 'fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'clicks' => 'integer',
            'impressions' => 'integer',
            'position' => 'float',
        ];
    }

    /**
     * 计算自然键指纹：site_url|date|page|query|country 拼 md5。
     * 与迁移中 fingerprint unique 列配套，语义等价于五元组唯一。
     */
    public static function makeFingerprint(string $siteUrl, string $date, string $page, string $query, ?string $country): string
    {
        return md5($siteUrl.'|'.$date.'|'.$page.'|'.$query.'|'.($country ?? ''));
    }
}
