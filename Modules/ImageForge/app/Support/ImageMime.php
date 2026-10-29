<?php

namespace Modules\ImageForge\Support;

/**
 * 图片附件判定：判断 Attachment 是否为需要转 WebP 的图片。
 */
class ImageMime
{
    /**
     * 优先看 mime 字段（image/* 且非 image/webp），mime 缺失时回退 path 后缀。
     * mime 命中后仍校验扩展白名单：SVG（GD 不可解码）与 GIF（动画会丢帧）不派发转换。
     */
    public static function convertible(?string $mime, ?string $path): bool
    {
        $mime = trim((string) $mime);

        if ($mime !== '') {
            if (! str_starts_with(strtolower($mime), 'image/')
                || str_starts_with(strtolower($mime), 'image/webp')) {
                return false;
            }
        }

        $extension = strtolower(pathinfo((string) $path, PATHINFO_EXTENSION));
        $allowed = (array) config('image-forge.extensions', ['jpg', 'jpeg', 'png']);

        return $extension !== '' && in_array($extension, $allowed, true);
    }
}
