<?php

return [
    // 插件总开关：关闭后买家确认邮件与管理员通知邮件均跳过（仅记录日志）
    'enabled' => env('MAIL_NOTIFIER_ENABLED', true),

    // 管理员收件人：逗号分隔字符串（与插件中心设置面板的类型一致，
    // 消费方自行解析；这里不能转数组，否则 plugin_setting 的类型语义会冲突）
    'admin_emails' => env('INQUIRY_ADMIN_EMAILS', ''),

    // 发件人名称（发件地址仍使用 config('mail.from.address')）
    'sender_name' => env('MAIL_FROM_NAME', 'BtoBcms'),
];
