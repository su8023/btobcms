<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Translatable\HasTranslations;

class Attribute extends Model
{
    use HasTranslations;

    protected $fillable = [
        'attr_set_id', 'code', 'name', 'type', 'unit', 'options',
        'is_filterable', 'is_required', 'sort_order',
    ];

    public array $translatable = ['name'];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'is_filterable' => 'boolean',
            'is_required' => 'boolean',
        ];
    }

    public function attrSet()
    {
        return $this->belongsTo(AttrSet::class);
    }
}
