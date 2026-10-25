<?php

return [
    /*
    |--------------------------------------------------------------------------
    | WhatsApp 浮动客服挂件
    |--------------------------------------------------------------------------
    |
    | enabled  总开关（模块启用且此项为 true 才注入挂件）
    | phone    国际号码纯数字，不含 + 号与空格，如 8613800138000
    | message  点击按钮后预填到 WhatsApp 输入框的开场文案
    | bubble   按钮 hover 时弹出的提示文案
    |
    */

    'enabled' => env('WHATSAPP_ENABLED', true),

    'phone' => env('WHATSAPP_NUMBER', ''),

    'message' => env('WHATSAPP_MESSAGE', 'Hello! I would like to get a quote.'),

    'bubble' => env('WHATSAPP_BUBBLE', 'Chat on WhatsApp'),
];
