<?php

return [

    'timeout' => 120,
    'retries' => 2,

    // 单次调用的输出上限（tokens）。不给硬上限时，模型可能一路生成到
    // 供应商的输出预算上限，成本与延迟都不可控。
    'max_tokens' => env('AI_MAX_TOKENS', 4000),

    // 自定义 provider（后台插件设置写入）时是否允许内网地址：
    // 自建/局域网部署的 OpenAI 兼容服务需要放行 10/8、172.16/12、192.168/16。
    // 回环与链路本地（云元数据）地址任何情况下都不放行。
    'allow_private_hosts' => env('AI_ALLOW_PRIVATE_HOSTS', false),

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
