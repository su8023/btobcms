<?php

return [
    // GitHub API（私有仓库需在插件设置里配 token）
    'github_api' => 'https://api.github.com',
    // 更新日志位置
    'log_path' => storage_path('app/update-center.log'),
    // 默认仓库：全新安装零配置即可一键更新（插件设置里可覆盖）
    'github_repo' => 'su8023/BtoBcms',
];
