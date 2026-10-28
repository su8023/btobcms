<?php

namespace Modules\GeoBoost\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class GeoFaq extends Model
{
    protected $fillable = ['faqable_type', 'faqable_id', 'locale', 'sort', 'question', 'answer', 'source'];

    public function faqable(): MorphTo
    {
        return $this->morphTo();
    }
}
