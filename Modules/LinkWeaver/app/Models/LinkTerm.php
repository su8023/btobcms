<?php

namespace Modules\LinkWeaver\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LinkTerm extends Model
{
    protected $fillable = ['keyword', 'target', 'title', 'locale', 'enabled'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    public function appliedLinks(): HasMany
    {
        return $this->hasMany(AppliedLink::class, 'term_id');
    }
}
