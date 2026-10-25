<?php

return [

    'timeout' => 120,
    'retries' => 2,

    // 能力档位 → provider。插件按档位取用，切换模型只改 env。
    'tiers' => [
        'chat' => env('AI_CHAT_PROVIDER', 'deepseek'),  // 常规生成（文章、TDK 优化）
        'cheap' => env('AI_CHEAP_PROVIDER', 'deepseek'), // 批量打分/分类等低成本任务
    ],

    // OpenAI 兼容协议，可接入任意兼容服务
    'providers' => [
        'deepseek' => [
            'base_url' => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com/v1'),
            'key' => env('DEEPSEEK_API_KEY'),
            'model' => env('DEEPSEEK_MODEL', 'deepseek-chat'),
        ],
        'glm' => [
            'base_url' => env('GLM_BASE_URL', 'https://open.bigmodel.cn/api/paas/v4'),
            'key' => env('GLM_API_KEY'),
            'model' => env('GLM_MODEL', 'glm-4-flash'),
        ],
        'openai' => [
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'key' => env('OPENAI_API_KEY'),
            'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        ],
    ],
];
