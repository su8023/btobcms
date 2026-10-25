<?php

// 静态页 TDK 本地化模板（嵌套结构，与 config/site.php 对齐；Seo::page 回退链：后台设置 → 语言包 → 配置）

return [
    'home' => [
        'title' => 'Reliable Manufacturer & Wholesale Supplier | {site}',
        'description' => 'Factory-direct B2B sourcing: OEM/ODM support, AQL quality control and worldwide shipping. Get a quote within 24 hours.',
    ],
    'products' => [
        'index' => [
            'title' => 'Wholesale Products from China | {site}',
            'description' => 'Browse the catalog: factory-direct wholesale prices, verified quality, flexible MOQ and OEM/ODM support for global buyers.',
        ],
    ],
    'blog' => [
        'index' => [
            'title' => 'Sourcing Guides & Industry Insights | {site}',
            'description' => 'Practical sourcing guides and industry insights from our export team — import smarter from China with confidence.',
        ],
    ],
    'services' => [
        'index' => [
            'title' => 'OEM, ODM, QC & Logistics Services | {site}',
            'description' => 'Full supply-chain service: OEM/ODM engineering, AQL quality control, global logistics and custom sourcing — one accountable partner from inquiry to delivery.',
        ],
    ],
    'contact' => [
        'title' => 'Contact Us — Quote in 24h | {site}',
        'description' => 'Send your inquiry: quotes, samples and OEM/ODM cooperation. Real human reply within 24 hours on business days.',
    ],
    'about' => [
        'title' => 'About Us — 10+ Years in Export | {site}',
        'description' => 'Meet the team behind {site}: factory network, AQL quality control and 10+ years serving B2B buyers worldwide.',
    ],
    'industries' => [
        'index' => [
            'title' => 'Industries We Serve — China Sourcing | {site}',
            'description' => 'Factory-direct sourcing across 18 industries: construction, machinery, medical, energy and more. OEM/ODM support, AQL quality control and worldwide shipping.',
        ],
    ],
    'privacy' => [
        'title' => 'Privacy Policy | {site}',
        'description' => 'How we collect, use and protect your personal data — GDPR rights, data retention and contact.',
    ],
    'terms' => [
        'title' => 'Terms of Service | {site}',
        'description' => 'How inquiries, quotations, orders, payment terms and quality claims work when sourcing with us.',
    ],
];
