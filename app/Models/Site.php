<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Site extends Model
{
    protected $fillable = [
        'name', 'domain', 'default_locale', 'locales', 'theme', 'settings', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'locales' => 'array',
            'settings' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
