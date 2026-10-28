<?php

return [
    // 总开关
    'enabled' => env('LEAD_LENS_ENABLED', true),

    // 热线阈值：≥ 该分飞书/列表标红优先跟进
    'hot_threshold' => 70,

    // 温线阈值
    'warm_threshold' => 40,

    // 高价值目标市场（ISO 国家码，表单 country 命中加分）
    'target_countries' => ['US', 'CA', 'GB', 'AU', 'DE', 'FR', 'NL', 'SE', 'JP', 'KR', 'AE', 'SA'],
];
