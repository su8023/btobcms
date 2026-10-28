<?php

namespace Database\Seeders;

use App\Models\AttrSet;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\Product;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        // 弱密码默认管理员仅限本地/测试环境：生产的管理员由安装向导创建。
        // 防止生产误跑 php artisan db:seed 后留下 Admin@2026 弱口令账号。
        if (! app()->environment('production')) {
            User::firstOrCreate(
                ['email' => 'admin@btobcms.local'],
                ['name' => 'Admin', 'password' => 'Admin@2026'],
            );
        }

        $this->call(ContentSeeder::class);
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
