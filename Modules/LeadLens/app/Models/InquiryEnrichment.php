<?php

namespace Modules\LeadLens\Models;

use App\Models\Inquiry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InquiryEnrichment extends Model
{
    protected $fillable = [
        'inquiry_id', 'score', 'tier', 'email_domain', 'is_free_email', 'company_site', 'details',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'is_free_email' => 'boolean',
            'details' => 'array',
        ];
    }

    public function inquiry(): BelongsTo
    {
        return $this->belongsTo(Inquiry::class);
    }

    public function tierColor(): string
    {
        return match ($this->tier) {
            'hot' => 'danger',
            'warm' => 'warning',
            default => 'gray',
        };
    }

    public function tierLabel(): string
    {
        return match ($this->tier) {
            'hot' => '热线',
            'warm' => '温线',
            default => '冷线',
        };
    }
}
