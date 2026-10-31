<?php

return [
    // 总开关：关闭后 FAQ 注入、AI 来访追踪、后台看板全部失效
    'enabled' => env('GEO_BOOST_ENABLED', true),

    // AI 引擎生成 FAQ 的数量
    'faq_count' => 5,

    // AI 引擎 referrer 识别清单（host 片段 → 展示名）
    'engines' => [
        'chatgpt.com' => 'ChatGPT',
        'openai.com' => 'ChatGPT',
        'perplexity.ai' => 'Perplexity',
        'gemini.google.com' => 'Gemini',
        'copilot.microsoft.com' => 'Copilot',
        'claude.ai' => 'Claude',
        'claude.com' => 'Claude',
        'you.com' => 'You.com',
        'grok.com' => 'Grok',
    ],

    // 看板统计窗口（天）
    'dashboard_days' => 30,

    // 同一访客 + 同一引擎的去重窗口（秒）。0 = 不去重（每次访问都记录，默认口径）。
    // 站点在 CDN 后面时客户端 IP 可能是 CDN 节点 IP，此时开启去重会把不同访客
    // 合并成一次，看板数据反而失真 —— 确认能拿到真实客户端 IP 后再开。
    'dedup_seconds' => env('GEO_BOOST_DEDUP_SECONDS', 0),
];
