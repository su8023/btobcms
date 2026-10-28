<?php

namespace Modules\GeoBoost\Models;

use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

class AiVisit extends Model
{
    use MassPrunable;

    public $timestamps = false;

    protected $fillable = ['engine', 'path', 'referer', 'created_at'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /** 看板只看 30 天窗口：保留 180 天供长期趋势，之前的由 model:prune 批量清理 */
    public function prunable()
    {
        return static::where('created_at', '<', now()->subDays(180));
    }
}
