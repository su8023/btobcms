<?php

namespace Modules\LinkWeaver\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppliedLink extends Model
{
    protected $fillable = ['post_id', 'term_id', 'locale'];

    public function term(): BelongsTo
    {
        return $this->belongsTo(LinkTerm::class, 'term_id');
    }
}
