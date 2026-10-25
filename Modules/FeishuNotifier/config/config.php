<?php

return [
    'enabled' => env('FEISHU_ENABLED', true),
    'webhook' => env('FEISHU_WEBHOOK_URL', ''),
    // 群机器人「签名校验」密钥（可选）
    'secret' => env('FEISHU_WEBHOOK_SECRET', ''),
    // 消息卡片里「打开后台」按钮地址
    'admin_url' => env('APP_URL', 'http://127.0.0.1:8000').'/admin',
];
