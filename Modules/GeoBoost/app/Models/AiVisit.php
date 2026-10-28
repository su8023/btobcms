<?php

namespace Modules\GeoBoost\Models;

use Illuminate\Database\Eloquent\Model;

class AiVisit extends Model
{
    public $timestamps = false;

    protected $fillable = ['engine', 'path', 'referer', 'created_at'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
