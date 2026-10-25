<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiCall extends Model
{
    protected $fillable = [
        'provider', 'model', 'purpose', 'prompt_tokens',
        'completion_tokens', 'status', 'error', 'user_id',
    ];
}
