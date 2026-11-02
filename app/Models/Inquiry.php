<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Modules\LeadLens\Models\InquiryEnrichment;

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
     * LeadLens 插件提供的评分增强（hasOne）。
     *
     * 关系是惰性的——类在真正访问时才解析。插件被删（目录被移除）后
     * 一旦访问本关系就会抛 Error: Class "..." not found，
     * 因此这里先判类是否存在，不可用时返回 null（调用方 null-safe）。
     */
    public function enrichment()
    {
        if (! class_exists(InquiryEnrichment::class)) {
            return null;
        }

        return $this->hasOne(InquiryEnrichment::class);
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
