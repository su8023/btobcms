<?php

namespace Modules\ImageForge\Jobs;

use App\Models\Attachment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\ImageForge\Services\WebpConverter;

/**
 * 附件图片 WebP 转换队列任务：
 * 依据附件自身的 disk/path 调用 WebpConverter；队列连接为 sync 时（如测试环境）同步执行。
 */
class ConvertAttachmentImage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** 最多尝试 2 次 */
    public int $tries = 2;

    public function __construct(public Attachment $attachment)
    {
    }

    public function handle(WebpConverter $converter): void
    {
        // disk 优先取附件自身字段（迁移默认 'public'），异常兜底 'public'
        $disk = (string) ($this->attachment->disk ?: 'public');

        $converter->convert((string) $this->attachment->path, $disk);
    }
}
