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
];
