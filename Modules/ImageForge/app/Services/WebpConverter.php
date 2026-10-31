<?php

namespace Modules\ImageForge\Services;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * WebP 转换器：纯 GD 实现（无第三方依赖）。
 *
 * 约定：webp 输出为同目录「原名 + .webp」兄弟文件（如 images/a.png → images/a.png.webp），
 * 原图永不删除/改名；转换失败吞异常记 Log::warning 并返回 null。
 */
class WebpConverter
{
    /**
     * 将盘上图片转换为 .webp 兄弟文件。
     *
     * @param  string  $path  盘上相对路径
     * @param  string  $disk  存储盘名
     * @return string|null 成功返回 webp 相对路径；非图片/失败返回 null
     */
    public function convert(string $path, string $disk = 'public'): ?string
    {
        // 统一分隔符与首尾斜杠，避免 Windows/fake 盘路径差异
        $path = trim(str_replace('\\', '/', $path), '/');

        if ($path === '') {
            return null;
        }

        // 原图已是 webp：兄弟文件与自身同路径，跳过以免自我覆盖
        if (str_ends_with(strtolower($path), '.webp')) {
            return null;
        }

        $webpPath = $path.'.webp';

        try {
            $store = Storage::disk($disk);

            if (! $store->exists($path)) {
                return null;
            }

            // 幂等：webp 兄弟已存在且不早于原图（mtime 按秒粒度，取 >= 防同秒抖动）则直接复用
            if ($store->exists($webpPath)
                && $store->lastModified($webpPath) >= $store->lastModified($path)) {
                return $webpPath;
            }

            $originalBytes = $store->get($path);

            if (! is_string($originalBytes) || $originalBytes === '') {
                return null;
            }

            // 解压炸弹防护：先只读文件头拿尺寸（不解码像素），
            // 再决定要不要真的解码。
            // 直接 imagecreatefromstring 的话，一张几十 KB 的图能撑出上亿像素，
            // 把 worker 的内存打满并直接 fatal（@ 拦不住 OOM）。
            $size = @getimagesizefromstring($originalBytes);

            if ($size === false) {
                Log::warning("ImageForge：无法识别图片尺寸，跳过转换 [{$disk}] {$path}");

                return null;
            }

            [$width, $height] = $size;
            $maxPixels = (int) config('image-forge.max_pixels', 40000000); // 默认 4000 万像素

            if ($width <= 0 || $height <= 0 || ($width * $height) > $maxPixels) {
                Log::warning("ImageForge：图片尺寸超出上限（{$width}x{$height}），跳过转换 [{$disk}] {$path}");

                return null;
            }

            $image = @imagecreatefromstring($originalBytes);

            if ($image === false) {
                Log::warning("ImageForge：无法解码图片，跳过转换 [{$disk}] {$path}");

                return null;
            }

            $this->encodeAndStore($store, $webpPath, $image);

            return $webpPath;
        } catch (\Throwable $e) {
            Log::warning("ImageForge：转换失败 [{$disk}] {$path}：{$e->getMessage()}");

            return null;
        }
    }

    /**
     * 缩放（最长边超限时等比缩到 max_edge）并以配置质量编码为 webp 写入盘上。
     * 方法结束时销毁涉及的所有 GdImage，防止内存泄漏。
     */
    private function encodeAndStore(Filesystem $store, string $webpPath, \GdImage $image): void
    {
        // 调色板图转真彩色，避免缩放/编码时失真
        if (function_exists('imagepalettetotruecolor') && ! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        $maxEdge = (int) config('image-forge.max_edge', config('image-forge.max_width', 1600));
        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);

        // 最长边超限：等比缩放，未超限保持原尺寸
        $target = $image;

        if ($longest > $maxEdge && $longest > 0) {
            $ratio = $maxEdge / $longest;
            $scaled = imagescale(
                $image,
                (int) max(1, round($width * $ratio)),
                (int) max(1, round($height * $ratio))
            );

            if ($scaled !== false) {
                $target = $scaled;
            }
        }

        $stream = fopen('php://temp', 'r+b');

        try {
            if (! imagewebp($target, $stream, (int) config('image-forge.quality', 82))) {
                throw new \RuntimeException('imagewebp 编码失败');
            }

            rewind($stream);
            $webpBytes = stream_get_contents($stream);
        } finally {
            fclose($stream);
            imagedestroy($target);

            // 缩放产生新图时，原图也要销毁（两者不同对象各销毁一次）
            if ($target !== $image) {
                imagedestroy($image);
            }
        }

        if (! is_string($webpBytes) || $webpBytes === '') {
            throw new \RuntimeException('webp 编码结果为空');
        }

        $store->put($webpPath, $webpBytes);
    }
}
