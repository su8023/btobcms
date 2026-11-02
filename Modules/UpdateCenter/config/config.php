<?php

return [
    // GitHub API（私有仓库需在插件设置里配 token）
    'github_api' => 'https://api.github.com',
    // 更新日志位置
    'log_path' => storage_path('app/update-center.log'),
    // 默认仓库：全新安装零配置即可一键更新（插件设置里可覆盖）。
    // env 兜底：fork/二开部署必须在 .env 改 UPDATE_CENTER_REPO，
    // 否则「一键更新」会从上游仓库拉包覆盖你的站（信任链问题）。
    'github_repo' => env('UPDATE_CENTER_REPO', 'su8023/BtoBcms'),
];
