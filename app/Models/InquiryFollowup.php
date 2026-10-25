<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InquiryFollowup extends Model
{
    protected $fillable = [
        'inquiry_id', 'user_id', 'channel', 'content', 'next_action_at',
    ];

    protected function casts(): array
    {
        return [
            'next_action_at' => 'datetime',
        ];
    }

    public function inquiry()
    {
        return $this->belongsTo(Inquiry::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
