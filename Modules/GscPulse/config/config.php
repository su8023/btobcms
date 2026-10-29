<?php

return [
    'enabled' => env('GSC_PULSE_ENABLED', true),

    // ---- 拉取窗口 ----
    // 数据窗口：起始日 = 今天 - days_back，结束日 = 昨天（GSC 最终数据有 1-2 天延迟）
    'days_back' => (int) env('GSC_PULSE_DAYS_BACK', 28),
    // 单页行数：GSC searchAnalytics 带维度查询的官方上限 2500
    'row_limit' => 2500,
    // 分页安全阀：最多循环 max_pages 页（2500 × 20 = 5 万行/天），防止异常响应死循环
    'max_pages' => 20,

    // ---- 衰退预警 ----
    // 近 7 天较前 7 天点击降幅百分比阈值（%），达到即上榜
    'diminishing_threshold' => (int) env('GSC_PULSE_DIMINISHING_THRESHOLD', 30),
    // 前 7 天点击量门槛：低于此值样本太小，忽略误报
    'diminishing_min_prev_clicks' => 10,

    // ---- 临门一脚（排名 4~15 的待冲首页词）----
    'near_miss' => [
        'min_position' => 4,        // 平均排名下限（比第 3 名更靠后才值得干预）
        'max_position' => 15,       // 平均排名上限（前 4 页内仍有流量）
        'min_impressions' => 100,   // 窗口内总展现门槛
    ],

    // ---- 机会词（有展现零点击）----
    'opportunity' => [
        'min_impressions' => 50,    // 窗口内总展现门槛
    ],

    // ---- OAuth ----
    // access_token 过期前的提前刷新缓冲（秒）
    'token_refresh_buffer' => 60,

    // ---- 看板 ----
    // 三个榜单各取前 N 条
    'board_limit' => 10,
];
