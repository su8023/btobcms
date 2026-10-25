<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

class Attachment extends Model
{
    use HasTranslations;

    protected $fillable = [
        'attachable_type', 'attachable_id', 'disk', 'path',
        'original_name', 'mime', 'size', 'alt', 'sort_order',
    ];

    public array $translatable = ['alt'];

    public function attachable()
    {
        return $this->morphTo();
    }
}
