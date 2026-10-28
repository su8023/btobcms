<?php

return [
    // 总开关：关闭后监听器不注册，已有内链保留
    'enabled' => env('LINK_WEAVER_ENABLED', true),

    // 单篇文章单语言最多自动织入的链接数（过多会被判过度优化）
    'max_links_per_post' => (int) env('LINK_WEAVER_MAX_LINKS', 3),
];
