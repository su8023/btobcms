<?php

return [
    'enabled' => env('IMAGE_FORGE_ENABLED', true),

    // 最长边超过该值时等比缩放（宽或高任一超过即缩放）
    'max_width' => 1600,

    // 单张图片允许的最大像素数（宽×高）：超过则不转换。
    // 解压炸弹防护 —— 一张几十 KB 的图能声明上亿像素，
    // 真去解码会把 worker 内存打满并直接 fatal。
    'max_pixels' => env('IMAGE_FORGE_MAX_PIXELS', 40000000),

    // imagewebp 编码质量（0-100）
    'quality' => 82,

    // 视为待转换图片的原始扩展名（webp 原图无需再转）
    'extensions' => ['jpg', 'jpeg', 'png'],
];
