<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Product;
use App\Models\SeoMeta;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 12 大类目 + 36 款标杆采购样例落库（对齐京东/淘宝/拼多多全行业类目架构，8 语言）。
 *
 * 数据源（唯一真源，禁止臆造）：
 *   database/data/sourcing_catalog_12.json
 *     ← 由 /root/work/representative_products_keywords.json + categories_12_spec.md 生成
 *
 * 幂等：按 slug upsert，可重复执行。
 */
class SourcingCatalog12Seeder extends Seeder
{
    /** 站点 locale（config('site.locales')） */
    private const LOCALES = ['en', 'es', 'ar', 'ru', 'pt', 'fr', 'de', 'it'];

    /** 旧的 9 个阀门类目（v1 已清理，这里保持幂等） */
    private const LEGACY_CATEGORY_SLUGS = [
        'industrial-valves', 'water-pumps', 'steel-pipes', 'centrifugal-pumps',
        'gate-valves', 'ball-valves', 'submersible-pumps', 'pipe-elbows', 'pipe-flanges',
    ];

    private const LEGACY_PRODUCT_SLUGS = [
        'stainless-gate-valve-dn50', 'brass-ball-valve-dn25',
        'horizontal-centrifugal-pump-2-2kw', 'submersible-pump-qdx10-36',
        'ss-90-degree-elbow-dn80', 'slip-on-flange-dn50-pn16',
    ];

    /** v1 过渡期的 6 个产业带类目（已被 12 大类取代，需清理） */
    private const V1_CATEGORY_SLUGS = [
        'consumer-electronics-iot', 'precision-cnc-industrial-hardware', 'silicone-plastics-tooling',
        'led-commercial-lighting-energy', 'packaging-printing-custom-boxes', 'apparel-fabrics-protective-gear',
    ];

    private const V1_PRODUCT_SLUGS = [
        'custom-cnc-machining-precision-lathe-parts', 'oem-odm-smart-electronics-turnkey-pcba-assembly',
        'high-precision-injection-molded-components-tooling', 'industrial-high-bay-led-lighting-energy-fixtures',
        'custom-corrugated-shipping-boxes-eco-packaging', 'heavy-duty-industrial-workwear-safety-apparel',
    ];

    /** 旧 URL → 新类目（301，避免历史链接直接 404） */
    private const V1_CATEGORY_REDIRECTS = [
        'consumer-electronics-iot' => '/category/consumer-electronics-appliances',
        'precision-cnc-industrial-hardware' => '/category/industrial-machinery-molds-cnc',
        'silicone-plastics-tooling' => '/category/industrial-machinery-molds-cnc',
        'led-commercial-lighting-energy' => '/category/commercial-lighting-new-energy',
        'packaging-printing-custom-boxes' => '/category/packaging-printing-office-supplies',
        'apparel-fabrics-protective-gear' => '/category/apparel-shoes-bags-accessories',
    ];

    public function run(): void
    {
        $data = $this->source();

        DB::transaction(function () use ($data): void {
            $this->purgeLegacyCatalog();
            $attrSetId = $this->seedAttrSet();
            $categoryIds = $this->seedCategories($data['categories'], $attrSetId);
            $this->seedProducts($data['products'], $categoryIds);
            $this->seedRedirects();
        });

        Category::flushCountCache();
    }

    /** 读取唯一数据源 */
    private function source(): array
    {
        $path = database_path('data/sourcing_catalog_12.json');

        if (! is_file($path)) {
            throw new \RuntimeException("数据源缺失：{$path}");
        }

        $data = json_decode((string) file_get_contents($path), true);

        if (! isset($data['categories'], $data['products']) || count($data['categories']) !== 12 || count($data['products']) !== 36) {
            throw new \RuntimeException('数据源格式错误：需要 12 个类目 + 36 个产品');
        }

        return $data;
    }

    /** 清理旧阀门类目/产品 + v1 过渡期目录 */
    private function purgeLegacyCatalog(): void
    {
        $categorySlugs = array_merge(self::LEGACY_CATEGORY_SLUGS, self::V1_CATEGORY_SLUGS);
        $productSlugs = array_merge(self::LEGACY_PRODUCT_SLUGS, self::V1_PRODUCT_SLUGS);

        $categoryIds = Category::query()->whereIn('slug', $categorySlugs)->pluck('id');
        $productIds = Product::query()->whereIn('slug', $productSlugs)->pluck('id');

        // 先删产品再删分类：products.category_id 是 FK(nullOnDelete)，
        // 反序会留下 category_id=NULL 的孤儿行。
        if ($productIds->isNotEmpty()) {
            $this->deleteSeoMeta('App\\Models\\Product', $productIds->all());
            Product::query()->whereIn('id', $productIds)->delete();
        }

        if ($categoryIds->isNotEmpty()) {
            $this->deleteSeoMeta('App\\Models\\Category', $categoryIds->all());
            Category::query()->whereIn('id', $categoryIds)->delete();
        }

        // 站内链接织入规则指向已删除产品
        $deadTerms = DB::table('link_terms')
            ->whereIn('target', array_map(fn (string $slug) => '/product/'.$slug, $productSlugs))
            ->pluck('id');

        if ($deadTerms->isNotEmpty()) {
            DB::table('applied_links')->whereIn('term_id', $deadTerms)->delete();
            DB::table('link_terms')->whereIn('id', $deadTerms)->delete();
        }
    }

    private function deleteSeoMeta(string $type, array $ids): void
    {
        DB::table('seo_meta')
            ->where('metable_type', $type)
            ->whereIn('metable_id', $ids)
            ->delete();
    }

    /** 规格表属性模板：沿用 sourcing-specs，补充 price_range */
    private function seedAttrSet(): int
    {
        DB::table('attr_sets')->updateOrInsert(
            ['code' => 'sourcing-specs'],
            ['name' => 'Sourcing Specifications', 'updated_at' => now(), 'created_at' => now()],
        );

        $attrSetId = (int) Schema::getConnection()->table('attr_sets')->where('code', 'sourcing-specs')->value('id');

        $names = [
            'material' => [
                'name' => [
                    'en' => 'Material', 'es' => 'Material', 'ar' => 'المادة', 'ru' => 'Материал', 'pt' => 'Material', 'fr' => 'Matériau', 'de' => 'Material', 'it' => 'Materiale',
                ],
                'unit' => null,
            ],
            'tolerance' => [
                'name' => [
                    'en' => 'Tolerance', 'es' => 'Tolerancia', 'ar' => 'التفاوت', 'ru' => 'Допуск', 'pt' => 'Tolerância', 'fr' => 'Tolérance', 'de' => 'Toleranz', 'it' => 'Tolleranza',
                ],
                'unit' => null,
            ],
            'certification' => [
                'name' => [
                    'en' => 'Certification', 'es' => 'Certificación', 'ar' => 'الشهادات', 'ru' => 'Сертификация', 'pt' => 'Certificação', 'fr' => 'Certification', 'de' => 'Zertifizierung', 'it' => 'Certificazione',
                ],
                'unit' => null,
            ],
            'lead_time' => [
                'name' => [
                    'en' => 'Lead time', 'es' => 'Plazo de entrega', 'ar' => 'مدة التسليم', 'ru' => 'Срок поставки', 'pt' => 'Prazo de entrega', 'fr' => 'Délai de livraison', 'de' => 'Lieferzeit', 'it' => 'Tempi di consegna',
                ],
                'unit' => 'days',
            ],
            'moq' => [
                'name' => [
                    'en' => 'MOQ', 'es' => 'Cantidad mínima', 'ar' => 'الحد الأدنى للطلب', 'ru' => 'Минимальный заказ', 'pt' => 'Quantidade mínima', 'fr' => 'Quantité minimale', 'de' => 'Mindestbestellmenge', 'it' => 'Ordine minimo',
                ],
                'unit' => null,
            ],
            'aql' => [
                'name' => [
                    'en' => 'Quality standard', 'es' => 'Norma de calidad', 'ar' => 'معيار الجودة', 'ru' => 'Стандарт качества', 'pt' => 'Norma de qualidade', 'fr' => 'Norme qualité', 'de' => 'Qualitätsstandard', 'it' => 'Standard di qualità',
                ],
                'unit' => null,
            ],
            'packing' => [
                'name' => [
                    'en' => 'Packing', 'es' => 'Embalaje', 'ar' => 'التغليف', 'ru' => 'Упаковка', 'pt' => 'Embalagem', 'fr' => 'Emballage', 'de' => 'Verpackung', 'it' => 'Imballaggio',
                ],
                'unit' => null,
            ],
            'port' => [
                'name' => [
                    'en' => 'Shipping port', 'es' => 'Puerto de embarque', 'ar' => 'ميناء الشحن', 'ru' => 'Порт отгрузки', 'pt' => 'Porto de embarque', 'de' => 'Versandhafen', 'it' => 'Porto di spedizione',
                ],
                'unit' => null,
            ],
            'power' => [
                'name' => [
                    'en' => 'Power', 'es' => 'Potencia', 'ar' => 'القدرة', 'ru' => 'Мощность', 'pt' => 'Potência', 'fr' => 'Puissance', 'de' => 'Leistung', 'it' => 'Potenza',
                ],
                'unit' => 'W',
            ],
            'tooling' => [
                'name' => [
                    'en' => 'Tooling', 'es' => 'Moldes', 'ar' => 'القوالب', 'ru' => 'Пресс-формы', 'pt' => 'Moldes', 'fr' => 'Outillage', 'de' => 'Werkzeugbau', 'it' => 'Stampi',
                ],
                'unit' => null,
            ],
            'sizing' => [
                'name' => [
                    'en' => 'Sizes', 'es' => 'Tallas', 'ar' => 'المقاسات', 'ru' => 'Размеры', 'pt' => 'Tamanhos', 'fr' => 'Tailles', 'de' => 'Größen', 'it' => 'Taglie',
                ],
                'unit' => null,
            ],
            'printing' => [
                'name' => [
                    'en' => 'Printing', 'es' => 'Impresión', 'ar' => 'الطباعة', 'ru' => 'Печать', 'pt' => 'Impressão', 'fr' => 'Impression', 'de' => 'Druck', 'it' => 'Stampa',
                ],
                'unit' => null,
            ],
            'price_range' => [
                'name' => [
                    'en' => 'Ex-factory price range', 'es' => 'Rango de precio de fábrica', 'ar' => 'نطاق سعر المصنع', 'ru' => 'Диапазон заводской цены', 'pt' => 'Faixa de preço de fábrica', 'fr' => 'Gamme de prix usine', 'de' => 'Preisspanne ab Werk', 'it' => 'Intervallo di prezzo di fabbrica',
                ],
                'unit' => null,
            ],
        ];

        foreach ($names as $code => $name) {
            DB::table('attributes')->updateOrInsert(
                ['attr_set_id' => $attrSetId, 'code' => $code],
                [
                    'name' => json_encode($name['name'], JSON_UNESCAPED_UNICODE),
                    'type' => 'text',
                    'unit' => $name['unit'],
                    'options' => null,
                    'is_filterable' => 0,
                    'is_required' => 0,
                    'sort_order' => 100,
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }

        return $attrSetId;
    }

    /**
     * 12 个一级类目。
     *
     * @return array<string, int> slug => id
     */
    private function seedCategories(array $categories, int $attrSetId): array
    {
        $ids = [];

        foreach ($categories as $definition) {
            $name = $definition['name'];
            $name['zh_CN'] = $definition['name_zh'] ?? ($name['en'] ?? '');

            $category = Category::query()->updateOrCreate(
                ['slug' => $definition['slug']],
                [
                    'site_id' => null,
                    'parent_id' => null,
                    'attr_set_id' => $attrSetId,
                    'name' => $name,
                    'description' => $definition['description'],
                    'core_keywords' => $definition['core_keywords'],
                    'hot_trending_keywords' => $definition['hot_trending_keywords'],
                    'sort_order' => (int) $definition['sort_order'],
                    'status' => true,
                ],
            );

            // 物化路径：根分类 = 自身 id（子树查询用 path LIKE 'x/%'）
            $category->path = (string) $category->id;
            $category->saveQuietly();

            $ids[$definition['slug']] = (int) $category->id;

            $this->upsertSeoMeta('App\\Models\\Category', $category->id, $definition);
        }

        return $ids;
    }

    /**
     * 36 款标杆采购样例。
     *
     * @param  array<string, int>  $categoryIds
     */
    private function seedProducts(array $products, array $categoryIds): void
    {
        $index = 0;

        foreach ($products as $definition) {
            $index++;

            $name = array_fill_keys(self::LOCALES, $definition['name_en']);
            $name['zh_CN'] = $definition['name_zh'];

            $product = Product::query()->updateOrCreate(
                ['slug' => $definition['slug']],
                [
                    'site_id' => null,
                    'category_id' => $categoryIds[$definition['category_slug']],
                    'sku' => 'SRC-'.str_pad((string) $index, 4, '0', STR_PAD_LEFT),
                    'status' => 'published',
                    'specs' => [
                        'moq' => $definition['moq'],
                        'price_range' => $definition['price_range'],
                        'certification' => $definition['certification'],
                        'lead_time' => $definition['lead_time'],
                        'aql' => $definition['aql'],
                        'port' => $definition['port'],
                    ],
                    'price_min' => $definition['price_min'],
                    'price_max' => $definition['price_max'],
                    'currency' => $definition['currency'],
                    'moq' => $definition['moq'],
                    'unit' => $definition['unit'],
                    'price_range' => $definition['price_range'],
                    'certification' => $definition['certification'],
                    'lead_time' => $definition['lead_time'],
                    'aql' => $definition['aql'],
                    'core_keywords' => $definition['core_keywords'],
                    'hot_trending_keywords' => $definition['hot_trending_keywords'],
                    'sort_order' => (int) $definition['sort_order'],
                    'name' => $name,
                    'summary' => $definition['summary'],
                    'description' => $definition['description'],
                ],
            );

            $this->upsertSeoMeta('App\\Models\\Product', $product->id, $definition);
        }
    }

    /**
     * SEO 关键词：core_keywords + hot_trending_keywords 写入 seo_meta.keywords（8 语言）。
     */
    private function upsertSeoMeta(string $type, int $id, array $definition): void
    {
        $keywords = implode(', ', array_merge($definition['core_keywords'], $definition['hot_trending_keywords']));

        DB::table('seo_meta')->updateOrInsert(
            ['metable_type' => $type, 'metable_id' => $id],
            [
                'title' => null,
                'description' => null,
                'keywords' => json_encode(array_fill_keys(self::LOCALES, $keywords), JSON_UNESCAPED_UNICODE),
                'canonical' => null,
                'robots' => null,
                'og' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
    }

    /** 旧 URL 的 301 */
    private function seedRedirects(): void
    {
        $paths = [];

        foreach (self::LEGACY_CATEGORY_SLUGS as $slug) {
            $paths['/category/'.$slug] = '/products';
        }

        foreach (self::LEGACY_PRODUCT_SLUGS as $slug) {
            $paths['/product/'.$slug] = '/products';
        }

        foreach (self::V1_CATEGORY_SLUGS as $slug) {
            $paths['/category/'.$slug] = self::V1_CATEGORY_REDIRECTS[$slug];
        }

        foreach (self::V1_PRODUCT_SLUGS as $slug) {
            $paths['/product/'.$slug] = '/products';
        }

        $paths['/old-products/gate-valve'] = '/products';

        foreach ($paths as $from => $to) {
            DB::table('redirects')->updateOrInsert(
                ['from_path' => $from],
                [
                    'site_id' => null,
                    'to_path' => $to,
                    'code' => 301,
                    'hits' => (int) (DB::table('redirects')->where('from_path', $from)->value('hits') ?? 0),
                    'updated_at' => now(),
                    'created_at' => now(),
                ],
            );
        }
    }
}
