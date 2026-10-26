<?php

return [
    // IndexNow 统一提交端点（提交到 api.indexnow.org 即 Bing/Yandex/Seznam 共享）
    'indexnow_endpoint' => 'https://api.indexnow.org/indexnow',
    // 密钥文件（首次推送自动生成）
    'key_file' => storage_path('app/indexnow.key'),
];