<?php

return [
    // git 可执行文件路径（宝塔默认在 PATH，可配绝对路径）
    'git_path' => env('UPDATE_CENTER_GIT_PATH', 'git'),
    // composer 可执行文件路径
    'composer_path' => env('UPDATE_CENTER_COMPOSER_PATH', 'composer'),
    // GitHub API（私有仓库需在插件设置里配 token）
    'github_api' => 'https://api.github.com',
    // 更新日志与备份位置
    'log_path' => storage_path('app/update-center.log'),
    'backup_dir' => storage_path('app/backups'),
    // 允许 git 工作区有未提交改动时仍继续（默认拒绝，防丢改动）
    'allow_dirty' => env('UPDATE_CENTER_ALLOW_DIRTY', false),
];
