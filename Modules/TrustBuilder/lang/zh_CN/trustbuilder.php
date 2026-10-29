<?php

// TrustBuilder 插件文案（中文；缺语言回退 en）
return [
    // 导航/面包屑文案
    'nav' => [
        'cases' => '客户案例',
        'certifications' => '认证证书',
    ],

    // 前台文案
    'front' => [
        'cases_subtitle' => '我们为全球客户交付的真实项目。',
        'cases_description' => '客户案例与认证证书——了解我们如何交付高质量的产品与服务。',
        'view_case' => '查看案例',
        'related_cases' => '相关案例',
        'back_to_cases' => '全部案例',
        'trust_title' => '值得信赖的合作伙伴',
        'cases_count' => ':count 个客户案例',
        'meta_customer' => '客户',
        'meta_industry' => '行业',
        'meta_country' => '国家/地区',
    ],

    // 后台（Filament）文案
    'admin' => [
        'cases' => '客户案例',
        'case' => '客户案例',
        'certifications' => '认证证书',
        'certification' => '认证证书',

        'section_basics' => '基本信息',
        'section_content' => '多语言内容',
        'section_media' => '图片素材',

        'slug' => 'URL 标识',
        'slug_helper' => '详情页地址 /case-studies/{slug}，建议小写英文加连字符',
        'customer_name' => '客户名称',
        'industry' => '行业',
        'industry_helper' => '相关案例按行业匹配',
        'country' => '国家/地区',
        'country_helper' => 'ISO 3166 代码，如 DE / US',
        'published' => '已发布',
        'published_helper' => '未发布的案例前台不可见（列表不展示、详情 404）',
        'status' => '状态',
        'unpublished' => '未发布',
        'sort' => '排序',
        'sort_helper' => '数值越小越靠前',
        'title' => '标题',
        'body' => '正文',
        'cover' => '封面图',
        'cover_helper' => '列表卡片与详情页顶部展示',
        'images' => '图集',
        'images_helper' => '可多选并拖拽排序',
        'cert_name' => '证书名称',
        'issuer' => '颁发机构',
        'cert_no' => '证书编号',
        'issued_at' => '签发日期',
        'expires_at' => '到期日期',
        'image' => '证书图片',
        'image_helper' => '证书扫描件或徽标，存 public 盘',
    ],
];
