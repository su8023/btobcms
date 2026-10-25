<?php

namespace Database\Seeders;

use App\Models\AttrSet;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\Product;
use App\Models\Site;
use Illuminate\Database\Seeder;

/**
 * 演示内容（站点记录/属性模板/分类树/示例产品）。
 * DatabaseSeeder（开发/测试）与安装向导 DemoContentSeeder 共用；
 * 管理员账号不在此处：测试用 DatabaseSeeder 的默认管理员，生产由安装向导创建。
 */
class ContentSeeder extends Seeder
{
    public function run(): void
    {
        $site = Site::firstOrCreate(
            ['domain' => null],
            ['name' => 'BtoBcms Main', 'default_locale' => 'en', 'locales' => ['en', 'es', 'ar', 'ru']],
        );

        // 属性模板：机械类示例（全品类 = 每个分类挂自己的模板）
        $machineSet = AttrSet::firstOrCreate(['code' => 'machinery'], ['name' => '机械通用']);
        Attribute::firstOrCreate(
            ['attr_set_id' => $machineSet->id, 'code' => 'power'],
            ['name' => ['en' => 'Power', 'zh' => '功率'], 'type' => 'text', 'unit' => 'kW'],
        );
        Attribute::firstOrCreate(
            ['attr_set_id' => $machineSet->id, 'code' => 'voltage'],
            ['name' => ['en' => 'Voltage', 'zh' => '电压'], 'type' => 'enum', 'options' => ['110V', '220V', '380V']],
        );

        // 分类树（无限级，物化路径）
        $industrial = $this->category($site->id, null, 'industrial-equipment', ['en' => 'Industrial Equipment', 'zh' => '工业设备']);
        $valves = $this->category($site->id, $industrial, 'valves', ['en' => 'Valves', 'zh' => '阀门'], $machineSet->id);
        $pumps = $this->category($site->id, $industrial, 'pumps', ['en' => 'Pumps', 'zh' => '泵'], $machineSet->id);
        $materials = $this->category($site->id, null, 'building-materials', ['en' => 'Building Materials', 'zh' => '建材']);
        $pipes = $this->category($site->id, $materials, 'steel-pipes', ['en' => 'Steel Pipes', 'zh' => '钢管']);

        // 示例产品
        $this->product($site->id, $valves, 'gate-valve-dn50', ['en' => 'Gate Valve DN50', 'zh' => '闸阀 DN50'], ['voltage' => '220V']);
        $this->product($site->id, $pumps, 'centrifugal-water-pump-2hp', ['en' => 'Centrifugal Water Pump 2HP', 'zh' => '离心水泵 2HP'], ['power' => '1.5', 'voltage' => '380V']);
        $this->product($site->id, $pipes, 'galvanized-steel-pipe-dn100', ['en' => 'Galvanized Steel Pipe DN100', 'zh' => '镀锌钢管 DN100']);
    }

    protected function category(int $siteId, ?Category $parent, string $slug, array $name, ?int $attrSetId = null): Category
    {
        $category = Category::firstOrCreate(
            ['site_id' => $siteId, 'slug' => $slug],
            [
                'parent_id' => $parent?->id,
                'attr_set_id' => $attrSetId,
                'name' => $name,
                'status' => true,
            ],
        );
        $category->rebuildPath();

        return $category;
    }

    protected function product(int $siteId, Category $category, string $slug, array $name, array $attrs = []): Product
    {
        return Product::firstOrCreate(
            ['site_id' => $siteId, 'slug' => $slug],
            [
                'category_id' => $category->id,
                'status' => 'published',
                'attrs' => $attrs ?: null,
                'moq' => '100 pcs',
                'name' => $name,
                'summary' => ['en' => $name['en'].' — factory direct supply, OEM available.'],
            ],
        );
    }
}
