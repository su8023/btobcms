<?php

namespace Modules\ImageForge\Providers;

use App\Models\Attachment;
use Illuminate\Support\Facades\Event;
use Modules\ImageForge\Console\Commands\ConvertImagesCommand;
use Modules\ImageForge\Jobs\ConvertAttachmentImage;
use Modules\ImageForge\Support\ImageMime;
use Nwidart\Modules\Support\ModuleServiceProvider;

class ImageForgeServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'ImageForge';

    protected string $nameLower = 'image-forge';

    /**
     * 模块 artisan 命令（nwidart ModuleServiceProvider::$commands 约定注册）。
     *
     * @var string[]
     */
    protected array $commands = [
        ConvertImagesCommand::class,
    ];

    public function boot(): void
    {
        parent::boot();

        $this->mergeConfigFrom(__DIR__.'/../../config/config.php', 'image-forge');
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'imageforge');

        if (! config('image-forge.enabled')) {
            return;
        }

        // 附件入库即自动转 WebP：仅对图片类附件派发队列任务；
        // 队列连接为 sync（如测试环境）时同步执行，同样生效。
        Event::listen('eloquent.created: '.Attachment::class, function (Attachment $attachment): void {
            if (ImageMime::convertible($attachment->mime, $attachment->path)) {
                ConvertAttachmentImage::dispatch($attachment);
            }
        });
    }
}
