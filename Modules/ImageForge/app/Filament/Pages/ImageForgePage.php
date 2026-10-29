<?php

namespace Modules\ImageForge\Filament\Pages;

use App\Models\Attachment;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;
use Modules\ImageForge\Jobs\ConvertAttachmentImage;
use Modules\ImageForge\Support\ImageMime;

/**
 * 图片 WebP 优化看板：
 * public 盘图片总数 / 已有 webp 数 / 估算节省字节 + 全量转换按钮（分块派发队列任务）。
 */
class ImageForgePage extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-photo';

    protected static ?int $navigationSort = 33;

    protected static ?string $slug = 'image-forge';

    protected string $view = 'imageforge::image-forge';

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return __('admin.groups.growth');
    }

    public static function getNavigationLabel(): string
    {
        return '图片 WebP 优化';
    }

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return '图片 WebP 优化';
    }

    public function getViewData(): array
    {
        $disk = Storage::disk('public');
        $allowed = (array) config('image-forge.extensions', ['jpg', 'jpeg', 'png']);

        $images = collect($disk->allFiles())
            ->filter(fn (string $path): bool => in_array(
                strtolower(pathinfo($path, PATHINFO_EXTENSION)),
                $allowed,
                true
            ));

        // 估算节省 = 各已转图片（原图字节 - 对应 webp 字节）之和
        $savedBytes = 0;
        $webpCount = 0;

        foreach ($images as $path) {
            $webpPath = $path.'.webp';

            if ($disk->exists($webpPath)) {
                $webpCount++;
                $savedBytes += max(0, (int) $disk->size($path) - (int) $disk->size($webpPath));
            }
        }

        return [
            'imageCount' => $images->count(),
            'webpCount' => $webpCount,
            'savedBytes' => $savedBytes,
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('convertAll')
                ->label('全量转换')
                ->action(fn () => $this->convertAll()),
        ];
    }

    /**
     * 全量转换：按 id 分块遍历附件，图片类逐条派发队列任务（队列异步处理，不阻塞请求）。
     */
    public function convertAll(): void
    {
        $dispatched = 0;

        Attachment::query()->chunkById(200, function ($attachments) use (&$dispatched): void {
            foreach ($attachments as $attachment) {
                if (ImageMime::convertible($attachment->mime, $attachment->path)) {
                    ConvertAttachmentImage::dispatch($attachment);
                    $dispatched++;
                }
            }
        });

        Notification::make()
            ->title($dispatched > 0 ? "已派发 {$dispatched} 个转换任务" : '没有需要转换的图片附件')
            ->success()
            ->send();
    }
}
