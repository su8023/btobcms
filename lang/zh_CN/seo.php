<?php

// 静态页 TDK 本地化模板（嵌套结构，与 config/site.php 对齐；Seo::page 回退链：后台设置 → 语言包 → 配置）

return [
    'home' => [
        'title' => '可靠制造商与批发供应商 | {site}',
        'description' => '工厂直供 B2B 采购：OEM/ODM 定制、AQL 质检、全球发货，24 小时内报价。',
    ],
    'products' => [
        'index' => [
            'title' => '中国批发产品 | {site}',
            'description' => '浏览产品目录：工厂批发价、品质保障、灵活起订量与 OEM/ODM 支持。',
        ],
    ],
    'blog' => [
        'index' => [
            'title' => '采购指南与行业洞察 | {site}',
            'description' => '来自出口团队的实用采购指南与行业洞察，助您放心从中国进口。',
        ],
    ],
    'services' => [
        'index' => [
            'title' => 'OEM/ODM、质检与物流服务 | {site}',
            'description' => '全链路服务：OEM/ODM 工程、AQL 质检、全球物流与定制寻源——从询盘到交付的单一责任伙伴。',
        ],
    ],
    'contact' => [
        'title' => '联系我们 — 24小时报价 | {site}',
        'description' => '提交询盘：报价、样品与 OEM/ODM 合作，工作日 24 小时内真人回复。',
    ],
    'about' => [
        'title' => '关于我们 — 10余年出口经验 | {site}',
        'description' => '{site} 团队：工厂网络、AQL 质检体系、10 余年服务全球 B2B 买家。',
    ],
    'industries' => [
        'index' => [
            'title' => '我们服务的行业 — 中国采购 | {site}',
            'description' => '覆盖 18 个行业的工厂直供寻源：建材、机械、医疗、能源等，OEM/ODM 支持、AQL 质检、全球发货。',
        ],
    ],
    'privacy' => [
        'title' => '隐私政策 | {site}',
        'description' => '我们如何收集、使用与保护您的个人信息——GDPR 权利与联系方式。',
    ],
    'terms' => [
        'title' => '服务条款 | {site}',
        'description' => '询盘、报价、订单、付款条款与质量索赔的处理方式。',
    ],
];
