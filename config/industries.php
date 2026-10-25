<?php

/**
 * 行业落地页配置：每个行业一个独立获客页 /industries/{slug}。
 *
 * name   按 locale 键名（8 语言），站点语言自动匹配
 * icon   首页/列表图标的 SVG path（24×24 网格，stroke 风格）
 * intro  行业导语（:name 占位 = 行业名），未覆盖的语言回退 en
 *
 * 增删行业：加/删一行即可，路由/页面/导航自动同步。
 */

return [
    [
        'slug' => 'construction-building',
        'name' => [
            'en' => 'Construction & Building', 'es' => 'Construcción y edificación',
            'ar' => 'البناء والتشييد', 'ru' => 'Строительство',
            'pt' => 'Construção civil', 'fr' => 'Construction et bâtiment',
            'de' => 'Bauwesen', 'it' => 'Costruzioni ed edilizia',
        ],
        'icon' => 'M3 21V9l6-4v4l6-4v4l6-4v16H3Z',
    ],
    [
        'slug' => 'water-plumbing',
        'name' => [
            'en' => 'Water Treatment & Plumbing', 'es' => 'Tratamiento de agua y fontanería',
            'ar' => 'معالجة المياه والسباكة', 'ru' => 'Водоснабжение и сантехника',
            'pt' => 'Tratamento de água e hidráulica', 'fr' => 'Traitement des eaux et plomberie',
            'de' => 'Wasseraufbereitung und Sanitär', 'it' => 'Trattamento acque e idraulica',
        ],
        'icon' => 'M12 2c3 4 6 7.5 6 11a6 6 0 0 1-12 0c0-3.5 3-7 6-11Z',
    ],
    [
        'slug' => 'automotive-machinery',
        'name' => [
            'en' => 'Automotive & Machinery', 'es' => 'Automoción y maquinaria',
            'ar' => 'السيارات والآلات', 'ru' => 'Автомобили и техника',
            'pt' => 'Automotivo e maquinaria', 'fr' => 'Automobile et machinerie',
            'de' => 'Automobil und Maschinenbau', 'it' => 'Automotive e macchinari',
        ],
        'icon' => 'M5 17h14M6 9l1.5-4h9L18 9M5 17v2m14-2v2M4 12h16a1 1 0 0 1 1 1v3H3v-3a1 1 0 0 1 1-1Z',
    ],
    [
        'slug' => 'agriculture-irrigation',
        'name' => [
            'en' => 'Agriculture & Irrigation', 'es' => 'Agricultura y riego',
            'ar' => 'الزراعة والري', 'ru' => 'Сельское хозяйство и орошение',
            'pt' => 'Agricultura e irrigação', 'fr' => 'Agriculture et irrigation',
            'de' => 'Landwirtschaft und Bewässerung', 'it' => 'Agricoltura e irrigazione',
        ],
        'icon' => 'M12 20V8m0 0c0-3 2-5 5-5-1 3-2 5-5 5Zm0 0c0-3-2-5-5-5 1 3 2 5 5 5ZM4 20h16',
    ],
    [
        'slug' => 'oil-gas-petrochemical',
        'name' => [
            'en' => 'Oil, Gas & Petrochemical', 'es' => 'Petróleo, gas y petroquímica',
            'ar' => 'النفط والغاز والبتروكيماويات', 'ru' => 'Нефть, газ и нефтехимия',
            'pt' => 'Petróleo, gás e petroquímica', 'fr' => 'Pétrole, gaz et pétrochimie',
            'de' => 'Öl, Gas und Petrochemie', 'it' => 'Petrolio, gas e petrolchimica',
        ],
        'icon' => 'M14 3v6a2 2 0 0 0 2 2h5M5 3v18h14V9l-6-6H7a2 2 0 0 0-2 2Z',
    ],
    [
        'slug' => 'hvac-utilities',
        'name' => [
            'en' => 'HVAC & Utilities', 'es' => 'Climatización y servicios',
            'ar' => 'التكييف والمرافق', 'ru' => 'Вентиляция и коммунальные системы',
            'pt' => 'HVAC e utilidades', 'fr' => 'CVC et services publics',
            'de' => 'HLK und Versorgung', 'it' => 'HVAC e utilities',
        ],
        'icon' => 'M12 3v18M5 8c2-3 12-3 14 0M7 16c2 2 8 2 10 0',
    ],
    [
        'slug' => 'packaging-retail',
        'name' => [
            'en' => 'Packaging & Retail', 'es' => 'Envases y retail',
            'ar' => 'التغليف والتجزئة', 'ru' => 'Упаковка и ритейл',
            'pt' => 'Embalagens e varejo', 'fr' => 'Emballage et retail',
            'de' => 'Verpackung und Einzelhandel', 'it' => 'Imballaggi e retail',
        ],
        'icon' => 'M21 8l-9-5-9 5v8l9 5 9-5V8ZM3 8l9 5 9-5M12 13v8',
    ],
    [
        'slug' => 'medical-laboratory',
        'name' => [
            'en' => 'Medical & Laboratory', 'es' => 'Médico y laboratorio',
            'ar' => 'الطبي والمختبرات', 'ru' => 'Медицина и лаборатории',
            'pt' => 'Médico e laboratório', 'fr' => 'Médical et laboratoire',
            'de' => 'Medizin und Labor', 'it' => 'Medicale e laboratorio',
        ],
        'icon' => 'M12 4v16M4 12h16M7 7h10v10H7Z',
    ],
    [
        'slug' => 'electrical-electronics',
        'name' => [
            'en' => 'Electrical & Electronics', 'es' => 'Eléctrico y electrónica',
            'ar' => 'الكهرباء والإلكترونيات', 'ru' => 'Электрика и электроника',
            'pt' => 'Elétrico e eletrônica', 'fr' => 'Électricité et électronique',
            'de' => 'Elektro und Elektronik', 'it' => 'Elettrico ed elettronica',
        ],
        'icon' => 'M13 2L4 14h6l-1 8 9-12h-6l1-8Z',
    ],
    [
        'slug' => 'lighting-led',
        'name' => [
            'en' => 'Lighting & LED', 'es' => 'Iluminación y LED',
            'ar' => 'الإنارة ومصابيح LED', 'ru' => 'Освещение и LED',
            'pt' => 'Iluminação e LED', 'fr' => 'Éclairage et LED',
            'de' => 'Beleuchtung und LED', 'it' => 'Illuminazione e LED',
        ],
        'icon' => 'M9 18h6M10 21h4M12 3a6 6 0 0 0-4 10c.6.6 1 1.2 1 2h6c0-.8.4-1.4 1-2a6 6 0 0 0-4-10Z',
    ],
    [
        'slug' => 'furniture-office',
        'name' => [
            'en' => 'Furniture & Office', 'es' => 'Muebles y oficina',
            'ar' => 'الأثاث ومستلزمات المكاتب', 'ru' => 'Мебель и офис',
            'pt' => 'Móveis e escritório', 'fr' => 'Mobilier et bureau',
            'de' => 'Möbel und Büro', 'it' => 'Arredamento e ufficio',
        ],
        'icon' => 'M4 18v-8h16v8M4 10V6h16v4M2 18h20M8 10V8h8v2',
    ],
    [
        'slug' => 'textile-apparel',
        'name' => [
            'en' => 'Textile & Apparel', 'es' => 'Textil y confección',
            'ar' => 'المنسوجات والملابس', 'ru' => 'Текстиль и одежда',
            'pt' => 'Têxtil e vestuário', 'fr' => 'Textile et habillement',
            'de' => 'Textil und Bekleidung', 'it' => 'Tessile e abbigliamento',
        ],
        'icon' => 'M8 3l4 4 4-4 5 5-3 3v10H6V11L3 8l5-5Z',
    ],
    [
        'slug' => 'food-beverage',
        'name' => [
            'en' => 'Food & Beverage Machinery', 'es' => 'Maquinaria de alimentos y bebidas',
            'ar' => 'آلات الأغذية والمشروبات', 'ru' => 'Пищевое оборудование',
            'pt' => 'Máquinas para alimentos e bebidas', 'fr' => 'Machines agroalimentaires',
            'de' => 'Nahrungsmittel- und Getränkemaschinen', 'it' => 'Macchinari alimentari e bevande',
        ],
        'icon' => 'M7 3v7a2 2 0 0 0 4 0V3M9 3v18M17 3c-1.5 2-2 4-2 6v3h3M17 3c1.5 2 2 4 2 6v3h-3',
    ],
    [
        'slug' => 'security-protection',
        'name' => [
            'en' => 'Security & Protection', 'es' => 'Seguridad y protección',
            'ar' => 'الأمن والحماية', 'ru' => 'Безопасность и защита',
            'pt' => 'Segurança e proteção', 'fr' => 'Sécurité et protection',
            'de' => 'Sicherheit und Schutz', 'it' => 'Sicurezza e protezione',
        ],
        'icon' => 'M12 3l8 3v6c0 4.5-3.2 7.7-8 9-4.8-1.3-8-4.5-8-9V6l8-3Z',
    ],
    [
        'slug' => 'tools-hardware',
        'name' => [
            'en' => 'Tools & Hardware', 'es' => 'Herramientas y ferretería',
            'ar' => 'الأدوات والهاردوير', 'ru' => 'Инструменты и метизы',
            'pt' => 'Ferramentas e hardware', 'fr' => 'Outillage et quincaillerie',
            'de' => 'Werkzeuge und Hardware', 'it' => 'Utensili e ferramenta',
        ],
        'icon' => 'M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.4 2.4-2.1-2.1 2.4-2.4Z',
    ],
    [
        'slug' => 'energy-solar',
        'name' => [
            'en' => 'Energy & Solar', 'es' => 'Energía y solar',
            'ar' => 'الطاقة والطاقة الشمسية', 'ru' => 'Энергетика и солнечная энергетика',
            'pt' => 'Energia e solar', 'fr' => 'Énergie et solaire',
            'de' => 'Energie und Solar', 'it' => 'Energia e solare',
        ],
        'icon' => 'M12 4v2m0 12v2M4 12H2m20 0h-2M6 6l1.5 1.5M16.5 16.5L18 18M18 6l-1.5 1.5M7.5 16.5L6 18M12 8a4 4 0 1 1 0 8 4 4 0 0 1 0-8Z',
    ],
    [
        'slug' => 'transportation-parts',
        'name' => [
            'en' => 'Transportation & Auto Parts', 'es' => 'Transporte y repuestos',
            'ar' => 'النقل وقطع الغيار', 'ru' => 'Транспорт и автозапчасти',
            'pt' => 'Transporte e autopeças', 'fr' => 'Transport et pièces auto',
            'de' => 'Transport und Kfz-Teile', 'it' => 'Trasporti e ricambi auto',
        ],
        'icon' => 'M5 17h14M6 9l1.5-4h9L18 9M5 17v2m14-2v2M3 13h18M7 13v-2h10v2',
    ],
    [
        'slug' => 'chemicals-materials',
        'name' => [
            'en' => 'Chemicals & New Materials', 'es' => 'Química y nuevos materiales',
            'ar' => 'الكيماويات والمواد الجديدة', 'ru' => 'Химия и новые материалы',
            'pt' => 'Química e novos materiais', 'fr' => 'Chimie et nouveaux matériaux',
            'de' => 'Chemie und neue Werkstoffe', 'it' => 'Chimica e nuovi materiali',
        ],
        'icon' => 'M10 3h4M12 3v6l5 9a2 2 0 0 1-1.8 3H8.8A2 2 0 0 1 7 18l5-9V3Z',
    ],
];
