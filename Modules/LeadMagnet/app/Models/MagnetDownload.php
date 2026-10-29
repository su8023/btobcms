<?php

namespace Modules\LeadMagnet\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * 下载记录：表单提交成功（非蜜罐）时写入，代表一封已发送签名链接的邮件。
 */
class MagnetDownload extends Model
{
    protected $fillable = ['magnet_id', 'email', 'locale', 'ip', 'utm'];

    protected function casts(): array
    {
        return [
            'utm' => 'array',
        ];
    }

    public function magnet()
    {
        return $this->belongsTo(LeadMagnet::class, 'magnet_id');
    }
}
