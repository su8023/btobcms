<?php

return [

    'name' => env('SITE_NAME', 'BtoBcms'),

    // 多语言：默认语言不带前缀，其余用 /es/ /ar/ /ru/ 子目录
    'locales' => ['en', 'es', 'ar', 'ru', 'pt', 'fr', 'de', 'it'],
    'default_locale' => 'en',
    'rtl_locales' => ['ar'],

    'native_names' => [
        'en' => 'English',
        'es' => 'Español',
        'ar' => 'العربية',
        'ru' => 'Русский',
        'pt' => 'Português',
        'fr' => 'Français',
        'de' => 'Deutsch',
        'it' => 'Italiano',
    ],

    'contact' => [
        'email' => env('SITE_EMAIL', 'sales@example.com'),
        'phone' => env('SITE_PHONE', '+86 755 0000 0000'),
        'address' => env('SITE_ADDRESS', 'Shenzhen, Guangdong, China'),
    ],

    // 统计：填 GA4 ID 即在 <head> 输出 gtag.js
    'analytics' => [
        'ga4' => env('GA4_ID'),
    ],

    // 首页 hero 热门搜索词（内链 + 快速发现；上线后按 GSC 真实搜索词更新）
    'hot_keywords' => ['gate valve', 'butterfly valve', 'water pump', 'steel pipe', 'DN50', 'OEM supplier'],

    /*
    |--------------------------------------------------------------------------
    | SEO 模板：模型级字段可覆盖；{name}/{site}/{category}/{moq} 为占位符
    |--------------------------------------------------------------------------
    */
    'seo' => [
        'home' => [
            'title' => 'Reliable Manufacturer & Wholesale Supplier from China | {site}',
            'description' => '{site} is a trusted B2B manufacturer and wholesale supplier from China. Factory prices, OEM/ODM support, global shipping. Get an instant quote today.',
        ],
        'products' => [
            'index' => [
                'title' => 'All Products | Manufacturers & Suppliers | {site}',
                'description' => 'Browse the full product catalog of {site}: factory-direct wholesale prices, verified quality, MOQ flexibility and OEM/ODM support.',
            ],
        ],
        'blog' => [
            'index' => [
                'title' => 'Blog & Sourcing Guides | {site}',
                'description' => 'Practical sourcing guides, product knowledge and industry insights from {site} to help global buyers source from China with confidence.',
            ],
        ],
        'contact' => [
            'title' => 'Contact Us | {site}',
            'description' => 'Contact {site} for quotes, samples and OEM/ODM cooperation. Email response within 24 hours.',
        ],
        'about' => [
            'title' => 'About Us | {site}',
            'description' => 'Learn about {site}: factory capabilities, quality control and 10+ years of B2B export experience.',
        ],
        'product' => [
            'title' => '{name} Manufacturer & Supplier | {site}',
            'description' => 'Buy {name} direct from {site}: wholesale factory price, MOQ {moq}, OEM/ODM available. Request a quote today.',
        ],
        'category' => [
            'title' => '{name} Manufacturers & Suppliers | {site}',
            'description' => 'Source {name} from verified manufacturers on {site}. Wholesale prices, quality inspection and OEM support.',
        ],
        'post' => [
            'title' => '{name} | {site}',
            'description' => 'Expert guide from {site}: sourcing tips, specifications and buying advice for global B2B buyers.',
        ],
    ],
];
