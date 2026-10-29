<?php

namespace Modules\ImageForge\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Modules\ImageForge\Services\WebpConverter;

/**
 * 批量转换 public 盘上的既有图片为 WebP 兄弟文件。
 * 大图集按 --chunk 分批处理并在批间回收内存，防止一次性处理过多大图。
 */
class ConvertImagesCommand extends Command
{
    protected $signature = 'imageforge:convert {--chunk=200 : 每批处理的文件数}';

    protected $description = '将 public 盘上的 jpg/jpeg/png 图片批量转换为 WebP 兄弟文件';

    public function handle(WebpConverter $converter): int
    {
        $disk = Storage::disk('public');
        $allowed = (array) config('image-forge.extensions', ['jpg', 'jpeg', 'png']);

        $images = collect($disk->allFiles())
            ->filter(fn (string $path): bool => in_array(
                strtolower(pathinfo($path, PATHINFO_EXTENSION)),
                $allowed,
                true
            ))
            ->values();

        if ($images->isEmpty()) {
            $this->info('public 盘没有可转换的图片。');

            return self::SUCCESS;
        }

        $converted = 0;
        $skipped = 0;
        $failed = 0;
        $chunkSize = max(1, (int) $this->option('chunk'));

        foreach ($images->chunk($chunkSize) as $chunk) {
            foreach ($chunk as $path) {
                $webpPath = $path.'.webp';

                // 已有较新的 webp 兄弟文件：幂等命中，记为跳过
                if ($disk->exists($webpPath)
                    && $disk->lastModified($webpPath) >= $disk->lastModified($path)) {
                    $skipped++;

                    continue;
                }

                if ($converter->convert($path) !== null) {
                    $converted++;
                } else {
                    $failed++;
                }
            }

            // 分批之间回收 GD 资源，防大图集内存峰值过高
            gc_collect_cycles();
        }

        $this->info("已转换 {$converted}，跳过 {$skipped}，失败 {$failed}。");

        return self::SUCCESS;
    }
}
