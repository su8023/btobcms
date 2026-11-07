<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 中国 6 大产业带寻源目录重建 + 旧阀门分类清理。
 *
 * 幂等：按 slug upsert，可重复执行。
 *
 * WHY 用 Seeder 而不是写死 SQL：
 * - 8 语言 JSON 字段（name/summary/description）用模型写入更安全（HasTranslations 的 cast 处理）；
 * - categories.path 是物化路径，必须在拿到自增 id 后回写，否则子树产品数永远算错；
 * - 清理动作要处理 seo_meta / redirects / link_terms 等旁路表，手写 SQL 容易漏。
 */
class SourcingCatalogSeeder extends Seeder
{
    /** 8 语言（站点 locale 集合：en, es, ar, ru, pt, fr, de, it） */
    private const LOCALES = ['en', 'es', 'ar', 'ru', 'pt', 'fr', 'de', 'it'];

    /** 旧阀门分类 slug（连同其下产品一并清理） */
    private const LEGACY_CATEGORY_SLUGS = [
        'industrial-valves',
        'water-pumps',
        'steel-pipes',
        'centrifugal-pumps',
        'gate-valves',
        'ball-valves',
        'submersible-pumps',
        'pipe-elbows',
        'pipe-flanges',
    ];

    /** 旧阀门产品 slug */
    private const LEGACY_PRODUCT_SLUGS = [
        'stainless-gate-valve-dn50',
        'brass-ball-valve-dn25',
        'horizontal-centrifugal-pump-2-2kw',
        'submersible-pump-qdx10-36',
        'ss-90-degree-elbow-dn80',
        'slip-on-flange-dn50-pn16',
    ];

    /**
     * 6 大产业带分类（name 8 语言；description 为高质量英文富文本）。
     *
     * @var array<int, array{slug: string, sort: int, name: array<string,string>, description: string}>
     */
    private const CATEGORIES = [
        [
            'slug' => 'consumer-electronics-iot',
            'sort' => 10,
            'name' => [
                'en' => 'Consumer Electronics & IoT',
                'es' => 'Electrónica de consumo e IoT',
                'ar' => 'الإلكترونيات الاستهلاكية وإنترنت الأشياء',
                'ru' => 'Потребительская электроника и IoT',
                'pt' => 'Eletrônicos de consumo e IoT',
                'fr' => 'Électronique grand public et IoT',
                'de' => 'Unterhaltungselektronik & IoT',
                'it' => 'Elettronica di consumo e IoT',
            ],
            'description' => '<p>Source from the Shenzhen–Dongguan electronics belt: PCBA assembly, IoT gateways, smart-home devices and box-build EMS. We audit the line, lock the process capability and hold every shipment to the same AQL standard before it leaves the factory.</p>'
                .'<ul><li>Shenzhen &amp; Dongguan: EMS, PCBA, IoT hardware, enclosure assembly</li>'
                .'<li>OEM/ODM from schematic and BOM to a shipped, tested unit</li>'
                .'<li>CE / RoHS / FCC documentation and test reports handled for you</li></ul>',
        ],
        [
            'slug' => 'precision-cnc-industrial-hardware',
            'sort' => 20,
            'name' => [
                'en' => 'Precision CNC & Industrial Hardware',
                'es' => 'Mecanizado CNC de precisión y componentes industriales',
                'ar' => 'التشغيل数控 CNC والأدوات الصناعية',
                'ru' => 'Прецизионная ЧПУ-обработка и промышленные комплектующие',
                'pt' => 'Usinagem CNC de precisão e componentes industriais',
                'fr' => 'Usinage CNC de précision et quincaillerie industrielle',
                'de' => 'Präzisions-CNC-Fertigung und Industriebauteile',
                'it' => 'Lavorazione CNC di precisione e componenti industriali',
            ],
            'description' => '<p>From the Foshan–Wenzhou–Changshu hardware belt we source turned, milled and ground parts to drawing: tolerances down to ±0.005 mm, ISO 9001 certified lines, full material traceability and CMM inspection reports shipped with the goods.</p>'
                .'<ul><li>Foshan / Wenzhou / Changshu: CNC turning, milling, grinding, stamping</li>'
                .'<li>DFM feedback before cutting so tolerance stays manufacturable</li>'
                .'<li>Material certificates, heat numbers and inspection photos on request</li></ul>',
        ],
        [
            'slug' => 'silicone-plastics-tooling',
            'sort' => 30,
            'name' => [
                'en' => 'Silicone, Plastics & Tooling',
                'es' => 'Silicona, plásticos y moldes',
                'ar' => 'السيليكون والبلاستيك وقوالب التشكيل',
                'ru' => 'Силикон, пластик и пресс-формы',
                'pt' => 'Silicone, plásticos e moldes',
                'fr' => 'Silicone, plastiques et outillage',
                'de' => 'Silikon, Kunststoff und Werkzeugbau',
                'it' => 'Silicone, plastica e stampi',
            ],
            'description' => '<p>Tooling and moulding from the Taizhou–Yuyao–Dongguan cluster: injection moulds, silicone and plastic parts, and OEM packaging. One partner takes the job from DFM and cavity design through first-shot approval to mass production.</p>'
                .'<ul><li>Taizhou / Yuyao / Dongguan: injection moulds, LSR silicone, plastic parts</li>'
                .'<li>Steel or aluminium tooling, T1 samples approved before mass production</li>'
                .'<li>Multi-cavity and multi-shot programmes with cavity-by-cavity inspection</li></ul>',
        ],
        [
            'slug' => 'led-commercial-lighting-energy',
            'sort' => 40,
            'name' => [
                'en' => 'LED Commercial Lighting & Energy',
                'es' => 'Iluminación LED comercial y energía',
                'ar' => 'الإضاءة التجارية بتقنية LED وأنظمة الطاقة',
                'ru' => 'Коммерческое LED-освещение и энергетика',
                'pt' => 'Iluminação LED comercial e energia',
                'fr' => 'Éclairage commercial LED et énergie',
                'de' => 'LED-Gewerbebeleuchtung & Energie',
                'it' => 'Illuminazione LED commerciale ed energia',
            ],
            'description' => '<p>The Zhongshan–Changzhou lighting belt: high-bay, linear, flood and street fixtures, plus driver and panel supply. We verify lumens, driver brand and thermal design in the factory and compare samples against your photometric data before bulk orders.</p>'
                .'<ul><li>Zhongshan / Changzhou: LED luminaires, drivers, emergency &amp; street lighting</li>'
                .'<li>Real lumen output and driver brand checked at the line, not on the datasheet</li>'
                .'<li>CE / RoHS / ENEC files and IES data provided for tender documents</li></ul>',
        ],
        [
            'slug' => 'packaging-printing-custom-boxes',
            'sort' => 50,
            'name' => [
                'en' => 'Packaging, Printing & Custom Boxes',
                'es' => 'Embalaje, impresión y cajas a medida',
                'ar' => 'التغليف والطباعة والصناديق المخصصة',
                'ru' => 'Упаковка, полиграфия и индивидуальные коробки',
                'pt' => 'Embalagem, impressão e caixas personalizadas',
                'fr' => 'Emballage, impression et boîtes sur mesure',
                'de' => 'Verpackung, Druck und individuelle Kartons',
                'it' => 'Imballaggi, stampa e scatole su misura',
            ],
            'description' => '<p>Packaging from the Cangnan–Guangzhou printing belt: corrugated shipping boxes, rigid cartons, custom inserts and eco-grade materials. We check board weight, print registration and box compression strength so the goods survive the voyage, not just the audit.</p>'
                .'<ul><li>Wenzhou Cangnan / Guangzhou: corrugated boxes, colour cartons, inserts</li>'
                .'<li>Recycled and FSC-certified board available for EU buyers</li>'
                .'<li>Structural sample and drop test before the print run is released</li></ul>',
        ],
        [
            'slug' => 'apparel-fabrics-protective-gear',
            'sort' => 60,
            'name' => [
                'en' => 'Apparel, Fabrics & Protective Gear',
                'es' => 'Ropa, tejidos y equipo de protección',
                'ar' => 'الملابس والأقمشة ومعدات الوقاية',
                'ru' => 'Одежда, ткани и средства защиты',
                'pt' => 'Vestuário, tecidos e equipamento de proteção',
                'fr' => 'Vêtements, tissus et équipements de protection',
                'de' => 'Bekleidung, Textilien und Schutzausrüstung',
                'it' => 'Abbigliamento, tessuti e dispositivi di protezione',
            ],
            'description' => '<p>Garment and PPE sourcing from the Quanzhou–Keqiao belt: workwear, protective clothing, fabrics and finished garments. We check fabric weight and composition, colour fastness and seam strength, and confirm that EN/ANSI certification matches the actual model shipped.</p>'
                .'<ul><li>Quanzhou / Keqiao: workwear, hi-vis, safety gear, knitted &amp; woven fabric</li>'
                .'<li>Sizes S–5XL, custom embroidery and private-label programmes</li>'
                .'<li>EN ISO 20471 and ANSI/ISEA 107 documentation matched to the sample</li></ul>',
        ],
    ];

    /**
     * 属性模板（分类规格表用）：code => [8 语言名, 单位]
     *
     * @var array<string, array{name: array<string,string>, unit: ?string}>
     */
    private const ATTRIBUTES = [
        'material' => [
            'name' => [
                'en' => 'Material', 'es' => 'Material', 'ar' => 'المادة', 'ru' => 'Материал',
                'pt' => 'Material', 'fr' => 'Matériau', 'de' => 'Material', 'it' => 'Materiale',
            ],
            'unit' => null,
        ],
        'tolerance' => [
            'name' => [
                'en' => 'Tolerance', 'es' => 'Tolerancia', 'ar' => 'التفاوت', 'ru' => 'Допуск',
                'pt' => 'Tolerância', 'fr' => 'Tolérance', 'de' => 'Toleranz', 'it' => 'Tolleranza',
            ],
            'unit' => null,
        ],
        'certification' => [
            'name' => [
                'en' => 'Certification', 'es' => 'Certificación', 'ar' => 'الشهادات', 'ru' => 'Сертификация',
                'pt' => 'Certificação', 'fr' => 'Certification', 'de' => 'Zertifizierung', 'it' => 'Certificazione',
            ],
            'unit' => null,
        ],
        'lead_time' => [
            'name' => [
                'en' => 'Lead time', 'es' => 'Plazo de entrega', 'ar' => 'مدة التسليم', 'ru' => 'Срок поставки',
                'pt' => 'Prazo de entrega', 'fr' => 'Délai de livraison', 'de' => 'Lieferzeit', 'it' => 'Tempi di consegna',
            ],
            'unit' => 'days',
        ],
        'moq' => [
            'name' => [
                'en' => 'MOQ', 'es' => 'Cantidad mínima', 'ar' => 'الحد الأدنى للطلب', 'ru' => 'Минимальный заказ',
                'pt' => 'Quantidade mínima', 'fr' => 'Quantité minimale', 'de' => 'Mindestbestellmenge', 'it' => 'Ordine minimo',
            ],
            'unit' => null,
        ],
        'aql' => [
            'name' => [
                'en' => 'Quality standard', 'es' => 'Norma de calidad', 'ar' => 'معيار الجودة', 'ru' => 'Стандарт качества',
                'pt' => 'Norma de qualidade', 'fr' => 'Norme qualité', 'de' => 'Qualitätsstandard', 'it' => 'Standard di qualità',
            ],
            'unit' => null,
        ],
        'packing' => [
            'name' => [
                'en' => 'Packing', 'es' => 'Embalaje', 'ar' => 'التغليف', 'ru' => 'Упаковка',
                'pt' => 'Embalagem', 'fr' => 'Emballage', 'de' => 'Verpackung', 'it' => 'Imballaggio',
            ],
            'unit' => null,
        ],
        'port' => [
            'name' => [
                'en' => 'Shipping port', 'es' => 'Puerto de embarque', 'ar' => 'ميناء الشحن', 'ru' => 'Порт отгрузки',
                'pt' => 'Porto de embarque', 'fr' => "Port d'expédition", 'de' => 'Versandhafen', 'it' => 'Porto di spedizione',
            ],
            'unit' => null,
        ],
        'power' => [
            'name' => [
                'en' => 'Power', 'es' => 'Potencia', 'ar' => 'القدرة', 'ru' => 'Мощность',
                'pt' => 'Potência', 'fr' => 'Puissance', 'de' => 'Leistung', 'it' => 'Potenza',
            ],
            'unit' => 'W',
        ],
        'tooling' => [
            'name' => [
                'en' => 'Tooling', 'es' => 'Moldes', 'ar' => 'القوالب', 'ru' => 'Пресс-формы',
                'pt' => 'Moldes', 'fr' => 'Outillage', 'de' => 'Werkzeugbau', 'it' => 'Stampi',
            ],
            'unit' => null,
        ],
        'sizing' => [
            'name' => [
                'en' => 'Sizes', 'es' => 'Tallas', 'ar' => 'المقاسات', 'ru' => 'Размеры',
                'pt' => 'Tamanhos', 'fr' => 'Tailles', 'de' => 'Größen', 'it' => 'Taglie',
            ],
            'unit' => null,
        ],
        'printing' => [
            'name' => [
                'en' => 'Printing', 'es' => 'Impresión', 'ar' => 'الطباعة', 'ru' => 'Печать',
                'pt' => 'Impressão', 'fr' => 'Impression', 'de' => 'Druck', 'it' => 'Stampa',
            ],
            'unit' => null,
        ],
    ];

    /**
     * 6 款「工厂直采 / 代采定制」标杆案例（每款绑定 1 个产业带分类）。
     *
     * @var array<int, array<string, mixed>>
     */
    private const PRODUCTS = [
        [
            'slug' => 'custom-cnc-machining-precision-lathe-parts',
            'category' => 'precision-cnc-industrial-hardware',
            'sku' => 'CNC-LATHE-ISO9001',
            'sort' => 10,
            'price_min' => 3.20,
            'price_max' => 28.00,
            'moq' => '100 pcs',
            'unit' => 'pcs',
            'name' => [
                'en' => 'Custom CNC Machining & Precision Lathe Parts',
                'es' => 'Mecanizado CNC a medida y componentes de torno de precisión',
                'ar' => 'تشغيل CNC مخصص وقطع كمان بدقة',
                'ru' => 'Изготовление деталей на станках с ЧПУ и токарной обработке',
                'pt' => 'Usinagem CNC sob medida e peças de torno de precisão',
                'fr' => 'Usinage CNC sur mesure et pièces de tour de précision',
                'de' => 'CNC-Zerspanung und Drehteile nach Maß',
                'it' => 'Lavorazione CNC su misura e componenti di tornitura di precisione',
            ],
            'summary' => 'Turned, milled and ground parts from audited ISO 9001 lines in the Foshan–Wenzhou hardware belt, tolerance to ±0.005 mm, material certificates and CMM inspection reports included.',
            'description' => '<p>A representative sourcing package from our precision hardware programme: parts machined to your drawing on audited ISO 9001 certified lines, with a full documentation trail.</p>'
                .'<ul><li><strong>Tolerance:</strong> ±0.005 mm on critical dimensions (general tolerance ISO 2768-f unless agreed otherwise)</li>'
                .'<li><strong>Materials:</strong> aluminium 6061-T6, SS304/316L, S45C, copper, brass, POM, PTFE and engineering plastics</li>'
                .'<li><strong>Processes:</strong> CNC turning, 3/4/5-axis milling, CNC grinding, Swiss-type turning, broaching, stamping</li>'
                .'<li><strong>Inspection:</strong> CMM report on first article and on a random 10% of each batch, plus SPC charts</li>'
                .'<li><strong>Quality standard:</strong> AQL 2.5 (ISO 2859-1) for the pre-shipment inspection</li>'
                .'<li><strong>Lead time:</strong> 20–30 days for stock material, 35–45 days for new material with mill certificates</li>'
                .'<li><strong>MOQ:</strong> 100 pcs per part number; mixed part numbers accepted into one consolidated shipment</li>'
                .'<li><strong>Packing:</strong> export carton with foam insert, individually labelled, palletised for container loading</li>'
                .'<li><strong>Shipping port:</strong> Shenzhen or Ningbo, EXW / FOB / CIF, air freight for small urgent lots</li></ul>'
                .'<p>Send a drawing, STEP file or a sample and we return a manufacturability review, an itemised quotation and the tooling cost split.</p>',
            'attrs' => [
                'material' => 'Aluminium 6061-T6, SS304/316L, S45C, brass, POM, PTFE',
                'tolerance' => '±0.005 mm on critical dimensions (ISO 2768-f general)',
                'certification' => 'ISO 9001:2015 factory, material certificates with heat numbers',
                'lead_time' => '20–30 days (stock material) / 35–45 days (new material)',
                'moq' => '100 pcs per part number, mixed PNs in one shipment',
                'aql' => 'AQL 2.5 (ISO 2859-1) pre-shipment inspection',
                'packing' => 'Export carton with foam insert, labelled and palletised',
                'port' => 'Shenzhen / Ningbo, EXW / FOB / CIF',
            ],
        ],
        [
            'slug' => 'oem-odm-smart-electronics-turnkey-pcba-assembly',
            'category' => 'consumer-electronics-iot',
            'sku' => 'PCBA-TURNKEY-CE',
            'sort' => 20,
            'price_min' => 1.50,
            'price_max' => 45.00,
            'moq' => '500 pcs',
            'unit' => 'pcs',
            'name' => [
                'en' => 'OEM/ODM Smart Electronics & Turnkey PCBA Assembly',
                'es' => 'Electrónica inteligente OEM/ODM y montaje PCBA llave en mano',
                'ar' => 'إلكترونيات ذكية OEM/ODM وتجميع PCBA متكامل',
                'ru' => 'OEM/ODM умная электроника и сборка PCBA под ключ',
                'pt' => 'Eletrônica inteligente OEM/ODM e montagem de PCBA completa',
                'fr' => 'Électronique intelligente OEM/ODM et assemblage PCBA clé en main',
                'de' => 'OEM/ODM-Smart-Elektronik und schlüsselfertige PCBA-Bestückung',
                'it' => 'Elettronica intelligente OEM/ODM e assemblaggio PCBA chiavi in mano',
            ],
            'summary' => 'Turnkey PCBA and box-build assembly from the Shenzhen–Dongguan EMS belt: schematic review, BOM sourcing, SMT, testing and certification handled end to end.',
            'description' => '<p>One partner from schematic to a tested, boxed unit on your dock — the way most of our electronics buyers actually want to buy.</p>'
                .'<ul><li><strong>Scope:</strong> PCB fabrication, SMT/THT assembly, firmware flashing, functional test, enclosure assembly and packing</li>'
                .'<li><strong>Quality standard:</strong> AQL 2.5 (ISO 2859-1); IPC-A-600 Class 2 workmanship; 100% functional test on finished units</li>'
                .'<li><strong>Certification:</strong> CE, RoHS and FCC (FCC on request), with test reports and declarations produced before shipment</li>'
                .'<li><strong>Components:</strong> genuine parts with dated traceability; alternates offered only with your written approval</li>'
                .'<li><strong>Lead time:</strong> 25–35 days for a standard build after component arrival</li>'
                .'<li><strong>MOQ:</strong> 500 pcs per model; prototypes from 5 pcs for pre-production validation</li>'
                .'<li><strong>Packing:</strong> anti-static tray into export carton, 40 pcs per carton, serial number listed</li>'
                .'<li><strong>Shipping port:</strong> Shenzhen or Hong Kong, EXW / FOB / CIF / DDP</li></ul>'
                .'<p>Send a schematic, Gerber file or a working sample — we return a DFM and BOM risk review, a quotation and an honest lead-time estimate within 24 hours.</p>',
            'attrs' => [
                'material' => 'FR4 PCB (1.6–2.0 mm), RoHS-compliant components, ABS/PC enclosure',
                'tolerance' => 'IPC-A-600 Class 2 workmanship',
                'certification' => 'CE / RoHS (FCC and ENEC on request)',
                'lead_time' => '25–35 days after component arrival',
                'moq' => '500 pcs per model, prototypes from 5 pcs',
                'aql' => 'AQL 2.5 (ISO 2859-1), 100% functional test',
                'packing' => 'Anti-static tray, export carton, 40 pcs/carton',
                'port' => 'Shenzhen / Hong Kong, EXW / FOB / CIF / DDP',
            ],
        ],
        [
            'slug' => 'high-precision-injection-molded-components-tooling',
            'category' => 'silicone-plastics-tooling',
            'sku' => 'IMD-TOOL-500T',
            'sort' => 30,
            'price_min' => 0.35,
            'price_max' => 6.50,
            'moq' => '500 pcs',
            'unit' => 'pcs',
            'name' => [
                'en' => 'High-Precision Injection Molded Components & Tooling',
                'es' => 'Componentes moldeados por inyección de alta precisión y moldes',
                'ar' => 'مكونات حقن عالية الدقة وقوالب',
                'ru' => 'Высокоточные литые детали и пресс-формы',
                'pt' => 'Componentes moldados por injeção de alta precisão e moldes',
                'fr' => 'Composants injectés haute précision et outillage',
                'de' => 'Hochpräzisions-Spritzgusskomponenten und Werkzeugbau',
                'it' => 'Componenti stampati ad alta precisione e stampi',
            ],
            'summary' => 'Injection moulding, LSR silicone and precision plastic parts from the Taizhou–Yuyao–Dongguan tooling cluster, with DFM, T1 samples and cavity-by-cavity inspection before mass production.',
            'description' => '<p>Tooling and moulding as one package: we design the cavity with your engineers, build the mould, approve T1 samples and then run the mass production in the same cluster.</p>'
                .'<ul><li><strong>Tooling:</strong> 500T steel or aluminium moulds, cavity tolerance ±0.02 mm, life quoted in shots</li>'
                .'<li><strong>Materials:</strong> ABS, PC, PP, PA66, POM, TPE, LSR silicone, PVC and food-grade grades</li>'
                .'<li><strong>Processes:</strong> injection moulding, LSR silicone, over-moulding, insert moulding, multi-cavity and multi-shot programmes</li>'
                .'<li><strong>Quality standard:</strong> AQL 2.5 (ISO 2859-1), first-article report approved before the mass run</li>'
                .'<li><strong>Lead time:</strong> 20–25 days for tooling, 5–15 days for the first mass production run</li>'
                .'<li><strong>MOQ:</strong> 500 pcs per part number</li>'
                .'<li><strong>Packing:</strong> bagged and boxed, 100 pcs per bag, lot and cavity number labelled</li>'
                .'<li><strong>Shipping port:</strong> Ningbo or Shanghai, EXW / FOB / CIF</li></ul>'
                .'<p>Send a 3D file or a physical sample and we return a DFM report, a mould cost estimate and the shot-life assumption behind the price.</p>',
            'attrs' => [
                'material' => 'ABS, PC, PP, PA66, POM, TPE, LSR silicone, PVC',
                'tolerance' => '±0.02 mm on the cavity, general tolerance ISO 29481',
                'certification' => 'ISO 9001 moulding partner, material grade report per lot',
                'lead_time' => '20–25 days tooling + 5–15 days first mass run',
                'moq' => '500 pcs per part number',
                'aql' => 'AQL 2.5 (ISO 2859-1), first-article approval',
                'packing' => 'Bagged and boxed, 100 pcs/bag, lot & cavity labelled',
                'port' => 'Ningbo / Shanghai, EXW / FOB / CIF',
                'tooling' => '500T steel or aluminium mould, shot life quoted',
            ],
        ],
        [
            'slug' => 'industrial-high-bay-led-lighting-energy-fixtures',
            'category' => 'led-commercial-lighting-energy',
            'sku' => 'LED-HBF-150W',
            'sort' => 40,
            'price_min' => 12.00,
            'price_max' => 68.00,
            'moq' => '50 pcs',
            'unit' => 'pcs',
            'name' => [
                'en' => 'Industrial High-Bay LED Lighting & Energy-Saving Fixtures',
                'es' => 'Iluminación LED industrial high-bay y luminarias de ahorro energético',
                'ar' => 'إضاءة LED صناعية عالية الكفاءة والملحقات الموفّرة للطاقة',
                'ru' => 'Промышленное LED-освещение high-bay и энергосберегающие светильники',
                'pt' => 'Iluminação LED industrial high-bay e luminárias de economia de energia',
                'fr' => 'Éclairage LED industriel high-bay et luminaires économes en énergie',
                'de' => 'Industrielle High-Bay-LED-Beleuchtung und energiesparende Leuchten',
                'it' => 'Illuminazione LED industriale high-bay e apparecchi a risparmio energetico',
            ],
            'summary' => 'High-bay, linear, flood and street LED fixtures from the Zhongshan–Changzhou lighting belt, with real lumen output, verified driver brands and CE/RoHS/ENEC files for tender submissions.',
            'description' => '<p>Lighting bought by the datasheet is where buyers get burned. We check the fixture in the factory: lumen output, driver brand, thermal path and IP rating, and we sample-test before the bulk run.</p>'
                .'<ul><li><strong>Range:</strong> 80–240 W high-bay, linear batten, flood, street and emergency luminaires, plus drivers and LED panels</li>'
                .'<li><strong>Efficiency:</strong> 140–160 lm/W, aluminium housing with passive thermal design, L70 50,000 h</li>'
                .'<li><strong>Quality standard:</strong> AQL 2.5 (ISO 2859-1) plus an electrical safety and lumen check on a random sample of every lot</li>'
                .'<li><strong>Certification:</strong> CE, RoHS, ENEC on request; IES files and photometric data for tenders</li>'
                .'<li><strong>Lead time:</strong> 20–30 days for standard models, 35–45 days for customised housing or optics</li>'
                .'<li><strong>MOQ:</strong> 50 pcs per model; mixed wattages accepted into one container</li>'
                .'<li><strong>Packing:</strong> export carton with foam, 4 pcs per carton</li>'
                .'<li><strong>Shipping port:</strong> Guangzhou or Zhongshan, EXW / FOB / CIF</li></ul>'
                .'<p>Send the IES file or the wattage and mounting height — we send a photometric comparison and the payback calculation before you commit.</p>',
            'attrs' => [
                'material' => 'Aluminium housing, PC diffuser, aluminium reflector',
                'tolerance' => '±0.5 mm housing dimensions, lumens within ±5% of nominal',
                'certification' => 'CE / RoHS, ENEC and FCC on request, IES data supplied',
                'lead_time' => '20–30 days standard / 35–45 days customised',
                'moq' => '50 pcs per model, mixed wattages in one container',
                'aql' => 'AQL 2.5 (ISO 2859-1) + electrical safety and lumen spot check',
                'packing' => 'Export carton with foam, 4 pcs/carton',
                'port' => 'Guangzhou / Zhongshan, EXW / FOB / CIF',
                'power' => '80–240 W, 140–160 lm/W, L70 50,000 h',
            ],
        ],
        [
            'slug' => 'custom-corrugated-shipping-boxes-eco-packaging',
            'category' => 'packaging-printing-custom-boxes',
            'sku' => 'PKG-BOX-ECO',
            'sort' => 50,
            'price_min' => 0.28,
            'price_max' => 2.40,
            'moq' => '500 pcs',
            'unit' => 'pcs',
            'name' => [
                'en' => 'Custom Corrugated Shipping Boxes & Eco Packaging',
                'es' => 'Cajas de envío de cartón corrugado a medida y embalaje ecológico',
                'ar' => 'علب شحن من الكرتون المضلع مخصصة وتغليف صديق للبيئة',
                'ru' => 'Изготовление гофрокоробов на заказ и экологичная упаковка',
                'pt' => 'Caixas de envio de papelão corrugado sob medida e embalagem ecológica',
                'fr' => 'Boîtes d’expédition en carton ondulé sur mesure et emballage écologique',
                'de' => 'Maßgeschneiderte Versandkisten aus Wellpappe und umweltfreundliche Verpackung',
                'it' => 'Scatole per spedizioni in cartone ondulato su misura e imballaggi ecosostenibili',
            ],
            'summary' => 'Corrugated shipping boxes, rigid cartons and custom inserts from the Cangnan–Guangzhou printing belt, with board weight, print registration and compression strength verified before the print run.',
            'description' => '<p>Packaging that survives the voyage, not just the audit. We verify board grade, print registration, die-cut accuracy and box compression strength before releasing the print run.</p>'
                .'<ul><li><strong>Products:</strong> 3-ply and 5-ply corrugated shipping boxes, colour cartons, rigid boxes, foam and pulp inserts, mailer boxes</li>'
                .'<li><strong>Materials:</strong> kraft liner, recycled and FSC-certified board for EU buyers, food-contact grades on request</li>'
                .'<li><strong>Printing:</strong> CMYK flexo or offset, 1–4 spot colours, matte or gloss varnish, blind and hot foil</li>'
                .'<li><strong>Quality standard:</strong> AQL 2.5 (ISO 2859-1); drop test and compression test on the structural sample</li>'
                .'<li><strong>Lead time:</strong> 12–18 days for plain boxes, 18–25 days for printed and die-cut boxes</li>'
                .'<li><strong>MOQ:</strong> 500 pcs per size; mixed sizes in one consolidation</li>'
                .'<li><strong>Packing:</strong> bundled with strapping and corner protection, export pallet</li>'
                .'<li><strong>Shipping port:</strong> Wenzhou or Ningbo, EXW / FOB / CIF</li></ul>'
                .'<p>Send the product dimensions and the weight per carton and we return a board grade proposal, a print-ready dieline and a landed cost per carton.</p>',
            'attrs' => [
                'material' => '3-ply / 5-ply kraft corrugated, recycled & FSC board available',
                'tolerance' => '±2 mm cut size, print registration ±1 mm',
                'certification' => 'FSC / ISO 9001 on request, food-contact grade for packaging',
                'lead_time' => '12–18 days plain / 18–25 days printed and die-cut',
                'moq' => '500 pcs per size, mixed sizes in one consolidation',
                'aql' => 'AQL 2.5 (ISO 2859-1), drop and compression test on sample',
                'packing' => 'Strapped bundle, corner protection, export pallet',
                'port' => 'Wenzhou / Ningbo, EXW / FOB / CIF',
                'printing' => 'CMYK flexo or offset, 1–4 spot colours, varnish & foil',
            ],
        ],
        [
            'slug' => 'heavy-duty-industrial-workwear-safety-apparel',
            'category' => 'apparel-fabrics-protective-gear',
            'sku' => 'PPE-WORKWEAR-HD',
            'sort' => 60,
            'price_min' => 6.80,
            'price_max' => 24.50,
            'moq' => '300 pcs',
            'unit' => 'pcs',
            'name' => [
                'en' => 'Heavy-Duty Industrial Workwear & Safety Apparel',
                'es' => 'Ropa de trabajo industrial resistente y ropa de seguridad',
                'ar' => 'ملابس عمل صناعية شديدة التحمل وملابس سلامة',
                'ru' => 'Сверхпрочная промышленная спецодежда и средства защиты',
                'pt' => 'Uniformes industriais de alta resistência e vestuário de segurança',
                'fr' => 'Vêtements de travail industriels renforcés et équipements de protection',
                'de' => 'Robuste Industriearbeitskleidung und Schutzbekleidung',
                'it' => 'Abbigliamento da lavoro industriale rinforzato e indumenti di sicurezza',
            ],
            'summary' => 'Workwear, hi-vis and protective clothing from the Quanzhou–Keqiao garment belt: fabric weight and composition, colour fastness and seam strength checked, with EN ISO 20471 and ANSI/ISEA 107 files matching the shipped model.',
            'description' => '<p>Protective clothing that is actually certified for the model you receive — not a certificate for a different batch.</p>'
                .'<ul><li><strong>Products:</strong> coveralls, hi-vis jackets and vests, work trousers, shirts, gloves, safety footwear and helmets</li>'
                .'<li><strong>Fabrics:</strong> 3/1 twill cotton, poly-cotton, polyester, ripstop, ripstop Oxford, flame-retardant and arc-flame grades</li>'
                .'<li><strong>Certification:</strong> EN ISO 20471, EN 388 and ANSI/ISEA 107 documentation matched to the confirmed sample</li>'
                .'<li><strong>Quality standard:</strong> AQL 2.5 (ISO 2859-1); seam strength, colour fastness and fabric weight checked on the line</li>'
                .'<li><strong>Lead time:</strong> 20–30 days for plain workwear, 30–40 days for hi-vis and coated programmes</li>'
                .'<li><strong>MOQ:</strong> 300 pcs per style and colour</li>'
                .'<li><strong>Packing:</strong> poly bag per piece, 50 pcs per export carton, size ratio labelled</li>'
                .'<li><strong>Shipping port:</strong> Xiamen or Ningbo, EXW / FOB / CIF</li></ul>'
                .'<p>Send the use case, climate and certification requirement — we return a fabric recommendation, a costed sample option and the certification route.</p>',
            'attrs' => [
                'material' => '3/1 twill cotton, poly-cotton, polyester, ripstop, FR grades',
                'tolerance' => '±1 cm on body measurements, size range S–5XL',
                'certification' => 'EN ISO 20471, EN 388, ANSI/ISEA 107 documentation',
                'lead_time' => '20–30 days plain workwear / 30–40 days hi-vis',
                'moq' => '300 pcs per style and colour',
                'aql' => 'AQL 2.5 (ISO 2859-1), seam strength and colour fastness checked',
                'packing' => 'Poly bag per piece, 50 pcs/carton, size ratio labelled',
                'port' => 'Xiamen / Ningbo, EXW / FOB / CIF',
                'sizing' => 'S–5XL, custom embroidery and private label',
            ],
        ],
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $this->purgeLegacyCatalog();
            $this->seedAttrSet();
            $categoryIds = $this->seedCategories();
            $this->seedProducts($categoryIds);
            $this->seedLegacyRedirects();
        });

        Category::flushCountCache();
    }

    /** 删除旧阀门分类 / 产品及其旁路数据（seo_meta / link_terms / applied_links） */
    private function purgeLegacyCatalog(): void
    {
        $legacyCategories = Category::query()
            ->whereIn('slug', self::LEGACY_CATEGORY_SLUGS)
            ->pluck('id');

        $legacyProducts = Product::query()
            ->whereIn('slug', self::LEGACY_PRODUCT_SLUGS)
            ->pluck('id');

        // 先删产品，再删分类：products.category_id 是 FK（nullOnDelete），
        // 先删分类会把产品变成 category_id=NULL 的孤儿行。
        if ($legacyProducts->isNotEmpty()) {
            DB::table('seo_meta')
                ->where('metable_type', 'App\\Models\\Product')
                ->whereIn('metable_id', $legacyProducts)
                ->delete();

            Product::query()->whereIn('id', $legacyProducts)->delete();
        }

        if ($legacyCategories->isNotEmpty()) {
            DB::table('seo_meta')
                ->where('metable_type', 'App\\Models\\Category')
                ->whereIn('metable_id', $legacyCategories)
                ->delete();

            Category::query()->whereIn('id', $legacyCategories)->delete();
        }

        // 站内链接织入规则指向已删除的产品：连同已织入记录一起清理
        $deadTerms = DB::table('link_terms')
            ->whereIn('target', [
                '/product/stainless-gate-valve-dn50',
                '/product/brass-ball-valve-dn25',
            ])
            ->pluck('id');

        if ($deadTerms->isNotEmpty()) {
            DB::table('applied_links')->whereIn('term_id', $deadTerms)->delete();
            DB::table('link_terms')->whereIn('id', $deadTerms)->delete();
        }
    }

    /** 属性模板：给 6 个产业带分类共用的规格表 */
    private function seedAttrSet(): void
    {
        $attrSet = DB::table('attr_sets')->updateOrInsert(
            ['code' => 'sourcing-specs'],
            ['name' => 'Sourcing Specifications', 'updated_at' => now(), 'created_at' => now()],
        );

        $attrSetId = Schema::getConnection()
            ->table('attr_sets')
            ->where('code', 'sourcing-specs')
            ->value('id');

        $sort = 0;
        foreach (self::ATTRIBUTES as $code => $definition) {
            DB::table('attributes')->updateOrInsert(
                ['attr_set_id' => $attrSetId, 'code' => $code],
                [
                    'name' => json_encode($definition['name'], JSON_UNESCAPED_UNICODE),
                    'type' => 'text',
                    'unit' => $definition['unit'],
                    'options' => null,
                    'is_filterable' => 0,
                    'is_required' => 0,
                    'sort_order' => $sort += 10,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }
    }

    /**
     * 6 大产业带分类。
     *
     * @return array<string, int> slug => id
     */
    private function seedCategories(): array
    {
        $attrSetId = Schema::getConnection()
            ->table('attr_sets')
            ->where('code', 'sourcing-specs')
            ->value('id');

        $ids = [];

        foreach (self::CATEGORIES as $definition) {
            $category = Category::query()->updateOrCreate(
                ['slug' => $definition['slug']],
                [
                    'site_id' => null,
                    'parent_id' => null,
                    'attr_set_id' => $attrSetId,
                    'name' => $definition['name'],
                    'description' => ['en' => $definition['description']],
                    'sort_order' => $definition['sort'],
                    'status' => true,
                ],
            );

            // 物化路径：根分类 = 自身 id（子树查询用 path LIKE 'x/%'，路径错则产品数恒为 0）
            $category->path = (string) $category->id;
            $category->saveQuietly();

            $ids[$definition['slug']] = (int) $category->id;
        }

        return $ids;
    }

    /**
     * 6 款标杆案例产品。
     *
     * @param  array<string, int>  $categoryIds
     */
    private function seedProducts(array $categoryIds): void
    {
        foreach (self::PRODUCTS as $definition) {
            Product::query()->updateOrCreate(
                ['slug' => $definition['slug']],
                [
                    'site_id' => null,
                    'category_id' => $categoryIds[$definition['category']],
                    'sku' => $definition['sku'],
                    'status' => 'published',
                    'attrs' => $definition['attrs'],
                    'price_min' => $definition['price_min'],
                    'price_max' => $definition['price_max'],
                    'currency' => 'USD',
                    'moq' => $definition['moq'],
                    'unit' => $definition['unit'],
                    'sort_order' => $definition['sort'],
                    'name' => $definition['name'],
                    'summary' => ['en' => $definition['summary']],
                    'description' => ['en' => $definition['description']],
                ],
            );
        }
    }

    /**
     * 旧阀门 URL 的 301：指向产品目录，避免历史链接直接 404。
     */
    private function seedLegacyRedirects(): void
    {
        $legacyPaths = [];

        foreach (self::LEGACY_CATEGORY_SLUGS as $slug) {
            $legacyPaths[] = '/category/'.$slug;
        }

        foreach (self::LEGACY_PRODUCT_SLUGS as $slug) {
            $legacyPaths[] = '/product/'.$slug;
        }

        foreach ($legacyPaths as $path) {
            DB::table('redirects')->updateOrInsert(
                ['from_path' => $path],
                [
                    'site_id' => null,
                    'to_path' => '/products',
                    'code' => 301,
                    'hits' => DB::table('redirects')->where('from_path', $path)->value('hits') ?? 0,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        // 旧的 301 指向已删除的产品详情页
        DB::table('redirects')
            ->where('from_path', '/old-products/gate-valve')
            ->update(['to_path' => '/products', 'updated_at' => now()]);
    }
}
