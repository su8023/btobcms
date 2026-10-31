<?php

return [
    'name' => 'AiSeoAuditor',

    // 巡检是否允许访问内网地址（10/8、172.16/12、192.168/16）。
    // 默认关闭：巡检目标是公网站点，放开内网等于给 SSRF 开门。
    // 仅当站点本身部署在内网、且确实要巡检内网地址时才显式打开。
    'allow_private_hosts' => env('AI_SEO_AUDITOR_ALLOW_PRIVATE_HOSTS', false),
];
