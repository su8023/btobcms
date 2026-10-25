<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttrSet extends Model
{
    protected $fillable = ['name', 'code'];

    public function attributes()
    {
        return $this->hasMany(Attribute::class)->orderBy('sort_order');
    }

    public function categories()
    {
        return $this->hasMany(Category::class);
    }
}
