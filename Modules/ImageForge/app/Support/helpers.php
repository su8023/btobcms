<?php

/*
 * ImageForge 模块助手：由 module.json 的 "files" 配置自动加载。
 * 提供 WebP 优化后的图片 URL 解析与 <picture> 标签输出。
 */

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

if (! function_exists('imageforge_webp_path')) {
    /**
     * 同名 .webp 兄弟文件相对路径（约定：原名后追加 .webp，如 images/a.png → images/a.png.webp）。
     */
    function imageforge_webp_path(string $path): string
    {
        return $path.'.webp';
    }
}

if (! function_exists('imageforge_src')) {
    /**
     * 取图片展示 URL：若 public 盘存在同目录同名 .webp 兄弟文件则返回其 URL，
     * 否则回退原图 URL。path 为盘上相对路径，空值安全。
     */
    function imageforge_src(?string $path): string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return '';
        }

        $disk = Storage::disk('public');
        $webpPath = imageforge_webp_path($path);

        return $disk->exists($webpPath) ? $disk->url($webpPath) : $disk->url($path);
    }
}

if (! function_exists('imageforge_picture')) {
    /**
     * 输出响应式图片标签：
     *  - 有 webp 兄弟文件：<picture><source type="image/webp" srcset="..."><img ...></picture>
     *  - 无 webp：退化为单个 <img loading="lazy">
     * $attrs 合并到 img 标签（默认自带 loading="lazy"，可覆盖）；空路径返回空串。
     */
    function imageforge_picture(?string $path, string $alt, array $attrs = []): HtmlString
    {
        $path = trim((string) $path);

        if ($path === '') {
            return new HtmlString('');
        }

        $disk = Storage::disk('public');
        $webpPath = imageforge_webp_path($path);

        // 默认懒加载，调用方可通过 $attrs 覆盖
        $attrHtml = '';
        foreach (array_merge(['loading' => 'lazy'], $attrs) as $key => $value) {
            $attrHtml .= ' '.e((string) $key).'="'.e((string) $value).'"';
        }

        $img = '<img src="'.e($disk->url($path)).'" alt="'.e($alt).'"'.$attrHtml.'>';

        if (! $disk->exists($webpPath)) {
            return new HtmlString($img);
        }

        return new HtmlString(
            '<picture>'
            .'<source type="image/webp" srcset="'.e($disk->url($webpPath)).'">'
            .$img
            .'</picture>'
        );
    }
}
