<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Inquiry extends Model
{
    public const STATUS_NEW = 'new';
    public const STATUS_READ = 'read';
    public const STATUS_FOLLOWING = 'following';
    public const STATUS_QUOTED = 'quoted';
    public const STATUS_WON = 'won';
    public const STATUS_LOST = 'lost';
    public const STATUS_SPAM = 'spam';

    public const STATUSES = [
        self::STATUS_NEW, self::STATUS_READ, self::STATUS_FOLLOWING,
        self::STATUS_QUOTED, self::STATUS_WON, self::STATUS_LOST, self::STATUS_SPAM,
    ];

    protected $fillable = [
        'site_id', 'name', 'email', 'phone', 'company', 'country', 'message',
        'status', 'assignee_id', 'source', 'utm', 'landing_url', 'referrer',
        'ip', 'user_agent', 'spam_score', 'last_contacted_at',
    ];

    protected function casts(): array
    {
        return [
            'utm' => 'array',
            'spam_score' => 'integer',
            'last_contacted_at' => 'datetime',
        ];
    }

    public function items()
    {
        return $this->hasMany(InquiryItem::class);
    }

    /**
     * LeadLens 插件提供的评分增强（hasOne，模块停用后不访问即无影响）。
     */
    public function enrichment()
    {
        return $this->hasOne(\Modules\LeadLens\Models\InquiryEnrichment::class);
    }

    public function followups()
    {
        return $this->hasMany(InquiryFollowup::class)->latest();
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }
}
