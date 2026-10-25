<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Redirect extends Model
{
    protected $fillable = ['site_id', 'from_path', 'to_path', 'code'];

    protected function casts(): array
    {
        return [
            'code' => 'integer',
        ];
    }
}
