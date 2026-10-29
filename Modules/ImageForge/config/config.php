<?php

return [
    'enabled' => env('IMAGE_FORGE_ENABLED', true),

    // 最长边超过该值时等比缩放（宽或高任一超过即缩放）
    'max_width' => 1600,

    // imagewebp 编码质量（0-100）
    'quality' => 82,

    // 视为待转换图片的原始扩展名（webp 原图无需再转）
    'extensions' => ['jpg', 'jpeg', 'png'],
];
