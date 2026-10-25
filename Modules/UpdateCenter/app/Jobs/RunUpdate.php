<?php

namespace Modules\UpdateCenter\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\UpdateCenter\Services\Updater;

/**
 * 一键更新队列任务：长操作（fetch/composer/migrate）移出 web 请求，防超时。
 * 进度实时写 storage/app/update-center.log，后台页面轮询展示。
 */
class RunUpdate implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 900;

    public function __construct(
        public readonly string $targetTag,
    ) {
    }

    public function handle(): void
    {
        Updater::fromSettings()->updateTo($this->targetTag);
    }

    public function failed(\Throwable $e): void
    {
        @file_put_contents(
            (string) config('update-center.log_path'),
            '!!! 更新任务异常退出：'.$e->getMessage()."\n",
            FILE_APPEND,
        );
    }
}
