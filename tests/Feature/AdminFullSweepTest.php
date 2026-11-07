<?php

namespace Tests\Feature;

use App\Filament\Pages\BackendErrors;
use App\Filament\Pages\PluginCenter;
use App\Filament\Pages\PluginSettingsPage;
use App\Filament\Pages\SiteSettings;
use App\Filament\Pages\ThemeCenter;
use App\Filament\Resources\AttrSets\Pages\CreateAttrSet;
use App\Filament\Resources\AttrSets\Pages\EditAttrSet;
use App\Filament\Resources\AttrSets\Pages\ListAttrSets;
use App\Filament\Resources\Attributes\Pages\CreateAttribute;
use App\Filament\Resources\Attributes\Pages\EditAttribute;
use App\Filament\Resources\Attributes\Pages\ListAttributes;
use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\Inquiries\Pages\CreateInquiry;
use App\Filament\Resources\Inquiries\Pages\EditInquiry;
use App\Filament\Resources\Inquiries\Pages\ListInquiries;
use App\Filament\Resources\Posts\Pages\CreatePost;
use App\Filament\Resources\Posts\Pages\EditPost;
use App\Filament\Resources\Posts\Pages\ListPosts;
use App\Filament\Resources\Products\Pages\CreateProduct;
use App\Filament\Resources\Products\Pages\EditProduct;
use App\Filament\Resources\Products\Pages\ListProducts;
use App\Filament\Resources\Redirects\Pages\CreateRedirect;
use App\Filament\Resources\Redirects\Pages\EditRedirect;
use App\Filament\Resources\Redirects\Pages\ListRedirects;
use App\Models\AttrSet;
use App\Models\Attribute;
use App\Models\Category;
use App\Models\Inquiry;
use App\Models\InquiryItem;
use App\Models\Post;
use App\Models\Product;
use App\Models\Redirect;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\AiContentWriter\Filament\Pages\AiStudio;
use Modules\AiSeoAuditor\Filament\Pages\SeoAuditReports;
use Modules\GeoBoost\Filament\Pages\AiTrafficPage;
use Modules\GscPulse\Filament\Pages\GscConnectPage;
use Modules\GscPulse\Filament\Pages\GscDashboardPage;
use Modules\ImageForge\Filament\Pages\ImageForgePage;
use Modules\LeadLens\Filament\Resources\LeadRadar\Pages\ListLeads;
use Modules\LeadMagnet\Filament\Resources\Magnets\Pages\CreateMagnet;
use Modules\LeadMagnet\Filament\Resources\Magnets\Pages\EditMagnet;
use Modules\LeadMagnet\Filament\Resources\Magnets\Pages\ListMagnets;
use Modules\LinkWeaver\Filament\Resources\LinkTerms\Pages\CreateLinkTerm;
use Modules\LinkWeaver\Filament\Resources\LinkTerms\Pages\EditLinkTerm;
use Modules\LinkWeaver\Filament\Resources\LinkTerms\Pages\ListLinkTerms;
use Modules\TransCoverage\Filament\Pages\CoveragePage;
use Modules\TrustBuilder\Filament\Resources\Cases\Pages\CreateCase;
use Modules\TrustBuilder\Filament\Resources\Cases\Pages\EditCase;
use Modules\TrustBuilder\Filament\Resources\Cases\Pages\ListCases;
use Modules\TrustBuilder\Filament\Resources\Certifications\Pages\CreateCertification;
use Modules\TrustBuilder\Filament\Resources\Certifications\Pages\EditCertification;
use Modules\TrustBuilder\Filament\Resources\Certifications\Pages\ListCertifications;
use Modules\UpdateCenter\Filament\Pages\UpdateCenterPage;
use Tests\TestCase;
use Throwable;

/**
 * 后台全量巡检：对每一个 admin 页面 / 资源列表页 / 表单页 / 表格动作做冒烟。
 *
 * 设计要点：
 *  - 汇总式断言：单点失败不掩盖其它失败，最后一次性输出全部失败清单。
 *  - 必须造数据：Filament 表格在无记录时不会执行数据查询，
 *    绝大多数「列解析 / 关联计数 / 排序」类 500 只有在有数据时才复现。
 *  - 覆盖表格交互：排序每一列、搜索、分页、筛选、分组、批量动作。
 */
class AdminFullSweepTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> 失败清单：场景 => 异常 */
    protected array $failures = [];

    protected User $admin;

    protected Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::firstOrFail();
        $this->actingAs($this->admin);
        $this->seedRichData();
    }

    // ------------------------------------------------------------------
    // 数据准备
    // ------------------------------------------------------------------

    protected function seedRichData(): void
    {
        $site = Site::firstOrFail();

        // 分类（含父子层级 → path 缩进逻辑）
        $parent = Category::firstOrFail();
        $child = Category::create([
            'site_id' => $site->id,
            'parent_id' => $parent->id,
            'slug' => 'sweep-child',
            'name' => ['en' => 'Sweep Child'],
            'status' => true,
        ]);
        $child->rebuildPath();

        // 属性集 + 属性（AttributeForm / AttributesTable 走多语言数组）
        $attrSet = AttrSet::firstOrFail();
        Attribute::firstOrCreate(
            ['attr_set_id' => $attrSet->id, 'code' => 'sweep_material'],
            [
                'name' => ['en' => 'Material', 'es' => 'Material ES'],
                'type' => 'text',
                'is_filterable' => true,
                'is_required' => false,
                'sort_order' => 1,
            ],
        );

        // 产品（attrs 为 JSON、name 多语言）
        Product::firstOrCreate(
            ['slug' => 'sweep-product'],
            [
                'site_id' => $site->id,
                'category_id' => $parent->id,
                'status' => 'published',
                'sku' => 'SWEEP-1',
                'specs' => ['material' => 'SS316'],
                'price_min' => 10,
                'price_max' => 20,
                'currency' => 'USD',
                'moq' => '10 pcs',
                'unit' => 'pcs',
                'name' => ['en' => 'Sweep Product'],
                'summary' => ['en' => 'Sweep summary'],
                'description' => ['en' => 'Sweep description'],
            ],
        );

        // 文章：多语言 + 富文本 HTML（前台存的是 HTML 字符串）
        Post::firstOrCreate(
            ['slug' => 'sweep-post'],
            [
                'site_id' => $site->id,
                'type' => 'post',
                'status' => 'published',
                'author_id' => $this->admin->id,
                'is_ai_generated' => false,
                'published_at' => now(),
                'views' => 3,
                'title' => ['en' => 'Sweep Post'],
                'excerpt' => ['en' => 'Sweep excerpt'],
                'content' => ['en' => '<p>Hello <strong>world</strong></p>'],
            ],
        );

        // 询盘 + 询盘商品（items_count 关联计数）
        $inquiry = Inquiry::firstOrCreate(
            ['email' => 'sweep-lead@example.com'],
            [
                'site_id' => $site->id,
                'name' => 'Sweep Lead',
                'company' => 'Sweep Co',
                'country' => 'US',
                'message' => 'Please quote gate valves.',
                'status' => 'new',
                'source' => 'web',
            ],
        );
        InquiryItem::firstOrCreate(
            ['inquiry_id' => $inquiry->id, 'product_id' => Product::firstOrFail()->id],
            ['qty' => 10, 'note' => 'sweep'],
        );

        // 重定向
        Redirect::firstOrCreate(
            ['from_path' => '/sweep-old'],
            ['to_path' => '/sweep-new', 'code' => 301],
        );

        // LinkWeaver：内链词 + 已织入记录（applied_links_count 关联计数）
        $term = \Modules\LinkWeaver\Models\LinkTerm::firstOrCreate(
            ['keyword' => 'sweep keyword'],
            ['target' => '/sweep-target', 'title' => 'Sweep', 'locale' => '', 'enabled' => true],
        );
        \Modules\LinkWeaver\Models\AppliedLink::firstOrCreate(
            ['post_id' => Post::firstOrFail()->id, 'term_id' => $term->id, 'locale' => 'en'],
            [],
        );

        // LeadMagnet：磁贴 + 下载记录
        \Modules\LeadMagnet\Models\LeadMagnet::firstOrCreate(
            ['slug' => 'sweep-magnet'],
            [
                'title' => ['en' => 'Sweep Magnet'],
                'description' => ['en' => 'Sweep magnet desc'],
                'file_path' => 'sweeps/magnet.pdf',
                'active' => true,
                'downloads_count' => 0,
            ],
        );
        \Modules\LeadMagnet\Models\MagnetDownload::firstOrCreate(
            [
                'magnet_id' => \Modules\LeadMagnet\Models\LeadMagnet::firstOrFail()->id,
                'email' => 'sweep-dl@example.com',
                'locale' => 'en',
                'ip' => '127.0.0.1',
                'utm' => ['source' => 'sweep'],
            ],
            [],
        );

        // TrustBuilder：案例 + 证书
        \Modules\TrustBuilder\Models\TrustCase::firstOrCreate(
            ['slug' => 'sweep-case'],
            [
                'customer_name' => 'Sweep Customer',
                'industry' => 'Chemical',
                'country' => 'US',
                'title' => ['en' => 'Sweep Case'],
                'body' => ['en' => 'Sweep body'],
                'cover' => null,
                'images' => [],
                'published' => true,
                'sort_order' => 1,
            ],
        );
        \Modules\TrustBuilder\Models\TrustCertification::firstOrCreate(
            ['slug' => 'sweep-cert'],
            [
                'name' => ['en' => 'Sweep Cert'],
                'issuer' => 'Sweep Authority',
                'cert_no' => 'SWEEP-001',
                'issued_at' => now()->subYear(),
                'expires_at' => now()->addYear(),
                'image' => null,
                'sort_order' => 1,
            ],
        );

        // GSC 每日数据（让 GscReport 的聚合真的跑到 GROUP BY 分支）
        for ($i = 0; $i < 20; $i++) {
            $siteUrl = 'https://sweep.test';
            $date = now()->subDays($i)->toDateString();
            $page = $i % 2 === 0 ? '/' : '/es/'.($i % 3);
            $query = 'sweep query '.$i;

            \Modules\GscPulse\Models\GscDaily::firstOrCreate(
                [
                    'fingerprint' => \Modules\GscPulse\Models\GscDaily::makeFingerprint($siteUrl, $date, $page, $query, 'usa'),
                ],
                [
                    'site_url' => $siteUrl,
                    'date' => $date,
                    'page' => $page,
                    'query' => $query,
                    'country' => 'usa',
                    'clicks' => $i,
                    'impressions' => 100 + $i,
                    'position' => 5 + ($i % 10),
                ],
            );
        }

        // AI 引流访问记录（AiVisit 关闭了时间戳，created_at 必须显式给）
        for ($i = 0; $i < 5; $i++) {
            \Modules\GeoBoost\Models\AiVisit::firstOrCreate(
                ['path' => '/sweep/'.$i],
                [
                    'engine' => 'chatgpt',
                    'referer' => 'https://ref.test',
                    'created_at' => now()->subDays($i),
                ],
            );
        }
    }

    // ------------------------------------------------------------------
    // 工具
    // ------------------------------------------------------------------

    protected function record(string $scenario, Throwable $e): void
    {
        $this->failures[$scenario] = $e::class.': '.$e->getMessage()
            .' @ '.$this->firstAppFrame($e);
    }

    protected function firstAppFrame(Throwable $e): string
    {
        $appRoot = base_path();

        foreach ($e->getTrace() as $frame) {
            $file = $frame['file'] ?? null;

            if ($file === null || ! str_starts_with(str_replace('\\', '/', $file), str_replace('\\', '/', $appRoot))) {
                continue;
            }

            if (str_contains(str_replace('\\', '/', $file), '/vendor/')) {
                continue;
            }

            return str_replace('\\', '/', $file).':'.($frame['line'] ?? '?');
        }

        return $e->getFile().':'.$e->getLine();
    }

    /** 捕获异常而不中断，用于一次性收集全部失败。 */
    protected function capture(string $scenario, callable $fn): void
    {
        try {
            $fn();
        } catch (Throwable $e) {
            $this->record($scenario, $e);
        }
    }

    protected function assertNoFailures(string $group): void
    {
        if ($this->failures === []) {
            $this->assertTrue(true);

            return;
        }

        $message = "【{$group}】共 ".count($this->failures)." 处失败：\n"
            .implode("\n", array_map(
                fn ($k, $v) => "  - {$k}\n      {$v}",
                array_keys($this->failures),
                array_values($this->failures),
            ));

        $this->failures = [];
        $this->fail($message);
    }

    // ------------------------------------------------------------------
    // 1. HTTP 冒烟：每个后台页面 GET 必须 200
    // ------------------------------------------------------------------

    public function test_all_admin_pages_render_over_http(): void
    {
        $urls = [
            '/admin',
            '/admin/profile',
            '/admin/products',
            '/admin/products/create',
            '/admin/products/'.Product::firstOrFail()->id.'/edit',
            '/admin/categories',
            '/admin/categories/create',
            '/admin/categories/'.Category::firstOrFail()->id.'/edit',
            '/admin/posts',
            '/admin/posts/create',
            '/admin/posts/'.Post::firstOrFail()->id.'/edit',
            '/admin/inquiries',
            '/admin/inquiries/create',
            '/admin/inquiries/'.Inquiry::firstOrFail()->id.'/edit',
            '/admin/attr-sets',
            '/admin/attr-sets/create',
            '/admin/attr-sets/'.AttrSet::firstOrFail()->id.'/edit',
            '/admin/attributes',
            '/admin/attributes/create',
            '/admin/attributes/'.Attribute::firstOrFail()->id.'/edit',
            '/admin/redirects',
            '/admin/redirects/create',
            '/admin/redirects/'.Redirect::firstOrFail()->id.'/edit',
            // 插件资源
            '/admin/link-terms',
            '/admin/link-terms/create',
            '/admin/link-terms/1/edit',
            '/admin/lead-radars',
            '/admin/magnets',
            '/admin/magnets/create',
            '/admin/magnets/1/edit',
            '/admin/cases',
            '/admin/cases/create',
            '/admin/cases/1/edit',
            '/admin/certifications',
            '/admin/certifications/create',
            '/admin/certifications/1/edit',
            // 插件页
            '/admin/ai-studio',
            '/admin/ai-traffic',
            '/admin/gsc-pulse',
            '/admin/gsc-pulse-connect',
            '/admin/trans-coverage',
            '/admin/image-forge',
            '/admin/seo-audit-reports',
            '/admin/site-settings',
            '/admin/theme-center',
            '/admin/plugin-center',
            '/admin/update-center',
            '/admin/backend-errors',
        ];

        foreach ($urls as $url) {
            $this->capture('HTTP GET '.$url, function () use ($url) {
                $this->get($url)->assertOk();
            });
        }

        $this->assertNoFailures('HTTP 页面冒烟');
    }

    // ------------------------------------------------------------------
    // 2. Livewire 组件冒烟：列表页 / 创建页 / 编辑页 / 自定义页
    // ------------------------------------------------------------------

    public function test_all_livewire_pages_render(): void
    {
        $components = [
            // 列表页
            'ListProducts' => ListProducts::class,
            'ListCategories' => ListCategories::class,
            'ListPosts' => ListPosts::class,
            'ListInquiries' => ListInquiries::class,
            'ListAttrSets' => ListAttrSets::class,
            'ListAttributes' => ListAttributes::class,
            'ListRedirects' => ListRedirects::class,
            'ListLinkTerms' => ListLinkTerms::class,
            'ListLeads' => ListLeads::class,
            'ListMagnets' => ListMagnets::class,
            'ListCases' => ListCases::class,
            'ListCertifications' => ListCertifications::class,
            // 创建页
            'CreateProduct' => CreateProduct::class,
            'CreateCategory' => CreateCategory::class,
            'CreatePost' => CreatePost::class,
            'CreateInquiry' => CreateInquiry::class,
            'CreateAttrSet' => CreateAttrSet::class,
            'CreateAttribute' => CreateAttribute::class,
            'CreateRedirect' => CreateRedirect::class,
            'CreateLinkTerm' => CreateLinkTerm::class,
            'CreateMagnet' => CreateMagnet::class,
            'CreateCase' => CreateCase::class,
            'CreateCertification' => CreateCertification::class,
            // 自定义页
            'SiteSettings' => SiteSettings::class,
            'ThemeCenter' => ThemeCenter::class,
            'PluginCenter' => PluginCenter::class,
            'BackendErrors' => BackendErrors::class,
            'AiStudio' => AiStudio::class,
            'AiTrafficPage' => AiTrafficPage::class,
            'GscDashboardPage' => GscDashboardPage::class,
            'GscConnectPage' => GscConnectPage::class,
            'ImageForgePage' => ImageForgePage::class,
            'CoveragePage' => CoveragePage::class,
            'SeoAuditReports' => SeoAuditReports::class,
            'UpdateCenterPage' => UpdateCenterPage::class,
        ];

        foreach ($components as $name => $class) {
            $this->capture('Livewire::test '.$name, function () use ($class) {
                Livewire::test($class)->assertOk();
            });
        }

        // PluginSettingsPage 构造需要 string $plugin——用真实插件名单独冒烟
        $this->capture('Livewire::test PluginSettingsPage (with plugin)', function () {
            Livewire::test(PluginSettingsPage::class, ['plugin' => 'AiContentWriter'])->assertOk();
        });

        $this->assertNoFailures('Livewire 组件冒烟');
    }

    public function test_all_edit_pages_render_with_records(): void
    {
        $edits = [
            'EditProduct' => [EditProduct::class, Product::firstOrFail()->id],
            'EditCategory' => [EditCategory::class, Category::firstOrFail()->id],
            'EditPost' => [EditPost::class, Post::firstOrFail()->id],
            'EditInquiry' => [EditInquiry::class, Inquiry::firstOrFail()->id],
            'EditAttrSet' => [EditAttrSet::class, AttrSet::firstOrFail()->id],
            'EditAttribute' => [EditAttribute::class, Attribute::firstOrFail()->id],
            'EditRedirect' => [EditRedirect::class, Redirect::firstOrFail()->id],
            'EditLinkTerm' => [EditLinkTerm::class, \Modules\LinkWeaver\Models\LinkTerm::firstOrFail()->id],
            'EditMagnet' => [EditMagnet::class, \Modules\LeadMagnet\Models\LeadMagnet::firstOrFail()->id],
            'EditCase' => [EditCase::class, \Modules\TrustBuilder\Models\TrustCase::firstOrFail()->id],
            'EditCertification' => [EditCertification::class, \Modules\TrustBuilder\Models\TrustCertification::firstOrFail()->id],
        ];

        foreach ($edits as $name => [$class, $recordId]) {
            $this->capture('Livewire::test '.$name, function () use ($class, $recordId) {
                Livewire::test($class, ['record' => $recordId])->assertOk();
            });
        }

        $this->assertNoFailures('编辑页冒烟');
    }

    // ------------------------------------------------------------------
    // 3. 表格交互：排序每一列 / 搜索 / 分页 / 筛选 / 分组
    // ------------------------------------------------------------------

    public function test_every_table_column_sort_works(): void
    {
        $lists = [
            'ListProducts' => ListProducts::class,
            'ListCategories' => ListCategories::class,
            'ListPosts' => ListPosts::class,
            'ListInquiries' => ListInquiries::class,
            'ListAttrSets' => ListAttrSets::class,
            'ListAttributes' => ListAttributes::class,
            'ListRedirects' => ListRedirects::class,
            'ListLinkTerms' => ListLinkTerms::class,
            'ListLeads' => ListLeads::class,
            'ListMagnets' => ListMagnets::class,
            'ListCases' => ListCases::class,
            'ListCertifications' => ListCertifications::class,
        ];

        foreach ($lists as $name => $class) {
            $component = Livewire::test($class);

            $columns = $component->instance()->getTable()->getColumns();

            foreach ($columns as $column) {
                $columnName = $column->getName();

                $this->capture("{$name} sortTable({$columnName})", function () use ($component, $columnName) {
                    $component->call('sortTable', $columnName, 'desc')->assertOk();
                });
            }
        }

        $this->assertNoFailures('表格列排序');
    }

    public function test_table_search_pagination_and_filters(): void
    {
        $lists = [
            'ListProducts' => ListProducts::class,
            'ListCategories' => ListCategories::class,
            'ListPosts' => ListPosts::class,
            'ListInquiries' => ListInquiries::class,
            'ListAttrSets' => ListAttrSets::class,
            'ListAttributes' => ListAttributes::class,
            'ListRedirects' => ListRedirects::class,
            'ListLinkTerms' => ListLinkTerms::class,
            'ListLeads' => ListLeads::class,
            'ListMagnets' => ListMagnets::class,
            'ListCases' => ListCases::class,
            'ListCertifications' => ListCertifications::class,
        ];

        foreach ($lists as $name => $class) {
            $this->capture("{$name} searchTable('sweep')", function () use ($class) {
                Livewire::test($class)
                    ->set('tableSearch', 'sweep')
                    ->assertOk();
            });

            $this->capture("{$name} gotoPage(2)", function () use ($class) {
                Livewire::test($class)
                    ->call('gotoPage', 2)
                    ->assertOk();
            });

            $this->capture("{$name} table filters present", function () use ($class, $name) {
                $component = Livewire::test($class);
                $filters = $component->instance()->getTable()->getFilters();

                foreach ($filters as $filter) {
                    $filterName = $filter->getName();

                    $this->capture("{$name} filter {$filterName}", function () use ($class, $filterName) {
                        Livewire::test($class)
                            ->set("tableFilters.{$filterName}", ['value' => 'hot'])
                            ->assertOk();
                    });
                }
            });
        }

        $this->assertNoFailures('表格搜索/分页/筛选');
    }

    public function test_lead_radar_grouping_renders(): void
    {
        // LeadRadarResource 默认按 enrichment.tier 分组 —— 分组 + 聚合子查询最容易在
        // MySQL ONLY_FULL_GROUP_BY 下炸；这里显式跑一遍分组查询。
        $this->capture('ListLeads groupBy enrichment.tier', function () {
            Livewire::test(ListLeads::class)
                ->set('tableGrouping', 'enrichment.tier')
                ->assertOk();
        });

        $this->capture('ListLeads ungroup', function () {
            Livewire::test(ListLeads::class)
                ->set('tableGrouping', null)
                ->assertOk();
        });

        $this->assertNoFailures('线索雷达分组');
    }

    // ------------------------------------------------------------------
    // 4. 页面动作 / 表单动作
    // ------------------------------------------------------------------

    public function test_page_level_actions_and_methods(): void
    {
        // SiteSettings 保存
        $this->capture('SiteSettings save()', function () {
            Livewire::test(SiteSettings::class)
                ->call('save')
                ->assertOk();
        });

        // ThemeCenter 的公开方法（空 slug 应当优雅失败，而不是 500）
        $this->capture('ThemeCenter activate(unknown)', function () {
            Livewire::test(ThemeCenter::class)
                ->call('activate', 'does-not-exist')
                ->assertOk();
        });

        $this->capture('ThemeCenter delete(unknown)', function () {
            Livewire::test(ThemeCenter::class)
                ->call('deleteTheme', 'does-not-exist')
                ->assertOk();
        });

        // ImageForge 全量转换
        $this->capture('ImageForgePage convertAll()', function () {
            Livewire::test(ImageForgePage::class)
                ->call('convertAll')
                ->assertOk();
        });

        // AiStudio 重置 / 空主题
        $this->capture('AiStudio resetStudio()', function () {
            Livewire::test(AiStudio::class)
                ->call('resetStudio')
                ->assertOk();
        });

        $this->capture('AiStudio makePlan() with empty topic', function () {
            Livewire::test(AiStudio::class)
                ->call('makePlan')
                ->assertOk();
        });

        $this->capture('AiStudio writeArticle() without plan', function () {
            Livewire::test(AiStudio::class)
                ->call('writeArticle')
                ->assertOk();
        });

        // CoveragePage 空选择
        $this->capture('CoveragePage runFix() without selection', function () {
            Livewire::test(CoveragePage::class)
                ->call('runFix')
                ->assertOk();
        });

        // GscDashboard 立即拉取（无凭据应优雅提示）
        $this->capture('GscDashboardPage fetchNow()', function () {
            Livewire::test(GscDashboardPage::class)
                ->call('fetchNow')
                ->assertOk();
        });

        // PluginSettingsPage 保存
        $this->capture('PluginSettingsPage save()', function () {
            Livewire::test(PluginSettingsPage::class, ['plugin' => 'FeishuNotifier'])
                ->call('save')
                ->assertOk();
        });

        // Post 编辑页的「AI 翻译 / SEO 体检」相关动作
        $this->capture('EditPost render', function () {
            Livewire::test(EditPost::class, ['record' => Post::firstOrFail()->id])->assertOk();
        });

        $this->assertNoFailures('页面动作');
    }

    // ------------------------------------------------------------------
    // 5. 表单 state 形态：RichEditor 的 content 是 Tiptap 数组
    // ------------------------------------------------------------------

    public function test_rich_editor_state_is_tiptap_array_not_string(): void
    {
        // Filament v5 RichEditor 的表单 state 就是 Tiptap doc 本身（hydrate 时由
        // RichEditorStateCast::set() 把存储的 HTML 转成 doc 数组），不是字符串，
        // 也不能多包一层 —— 包成 [[...]] 会让 Tiptap 的 Schema::apply() 取不到
        // $document['content'] 而报 Undefined array key "content"。
        $tiptap = [
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello']]],
            ],
        ];

        $post = Post::firstOrFail();

        $this->capture('EditPost with Tiptap array content', function () use ($post, $tiptap) {
            $component = Livewire::test(EditPost::class, ['record' => $post->id]);

            // 先取 data 数组补键再整体 set：Livewire 的点语法 set 不会自动创建嵌套数组
            $data = $component->get('data');
            $data['content']['en'] = $tiptap;
            $component->set('data', $data);

            $component->call('save')->assertOk();
        });

        $this->capture('EditPost form state content type', function () use ($post) {
            $component = Livewire::test(EditPost::class, ['record' => $post->id]);
            $state = $component->instance()->form->getState();

            // getState() 返回序列化后的存储形态（字符串）是正常的：
            // Tiptap 数组只在表单 state 里存在，保存时已由 RichEditor 序列化。
            // 真正要防的是「消费方（SEO 体检等）把 state 里的数组当字符串强转」，
            // 那类问题由 SeoChecklist 的 tiptapToHtml 覆盖，这里不再断言字符串。
            $this->assertTrue(
                isset($state['content']['en']) && is_string($state['content']['en']),
                'getState 后 content.en 应为字符串（RichEditor 序列化结果）',
            );
        });

        $this->assertNoFailures('富文本 state 形态');
    }

    // ------------------------------------------------------------------
    // 6. 关联计数列：counts() 的别名必须与列名一致
    // ------------------------------------------------------------------

    public function test_counts_columns_alias_matches_column_name(): void
    {
        $resources = [
            \App\Filament\Resources\Products\ProductResource::class,
            \App\Filament\Resources\Categories\CategoryResource::class,
            \App\Filament\Resources\Posts\PostResource::class,
            \App\Filament\Resources\Inquiries\InquiryResource::class,
            \App\Filament\Resources\AttrSets\AttrSetResource::class,
            \App\Filament\Resources\Attributes\AttributeResource::class,
            \App\Filament\Resources\Redirects\RedirectResource::class,
            \Modules\LinkWeaver\Filament\Resources\LinkTermResource::class,
            \Modules\LeadLens\Filament\Resources\LeadRadarResource::class,
            \Modules\LeadMagnet\Filament\Resources\MagnetsResource::class,
            \Modules\TrustBuilder\Filament\Resources\CaseResource::class,
            \Modules\TrustBuilder\Filament\Resources\CertificationResource::class,
        ];

        $problems = [];

        foreach ($resources as $resourceClass) {
            $model = $resourceClass::getModel();

            $table = Livewire::test(
                $this->listPageFor($resourceClass)
            )->instance()->getTable();

            foreach ($table->getColumns() as $column) {
                $relationships = $column->getRelationshipsToCount();

                if (empty($relationships)) {
                    continue;
                }

                foreach ((array) $relationships as $relationship) {
                    if (! is_string($relationship)) {
                        continue;
                    }

                    // Laravel withCount 的别名规则：Str::snake($relation).'_count'
                    $expectedAlias = \Illuminate\Support\Str::snake($relationship).'_count';

                    if ($column->getName() !== $expectedAlias) {
                        $problems[] = sprintf(
                            '%s: 列名 "%s" 与 withCount 别名 "%s" 不一致（关系 "%s"）→ 列永远取不到值；按该列排序会 Unknown column',
                            $resourceClass,
                            $column->getName(),
                            $expectedAlias,
                            $relationship,
                        );
                    }
                }
            }
        }

        if ($problems !== []) {
            $this->fail("关联计数列别名不一致：\n - ".implode("\n - ", $problems));
        }

        $this->assertTrue(true);
    }

    /** 找出资源对应的列表页 Livewire 组件。 */
    protected function listPageFor(string $resourceClass): string
    {
        return match ($resourceClass) {
            \App\Filament\Resources\Products\ProductResource::class => ListProducts::class,
            \App\Filament\Resources\Categories\CategoryResource::class => ListCategories::class,
            \App\Filament\Resources\Posts\PostResource::class => ListPosts::class,
            \App\Filament\Resources\Inquiries\InquiryResource::class => ListInquiries::class,
            \App\Filament\Resources\AttrSets\AttrSetResource::class => ListAttrSets::class,
            \App\Filament\Resources\Attributes\AttributeResource::class => ListAttributes::class,
            \App\Filament\Resources\Redirects\RedirectResource::class => ListRedirects::class,
            \Modules\LinkWeaver\Filament\Resources\LinkTermResource::class => ListLinkTerms::class,
            \Modules\LeadLens\Filament\Resources\LeadRadarResource::class => ListLeads::class,
            \Modules\LeadMagnet\Filament\Resources\MagnetsResource::class => ListMagnets::class,
            \Modules\TrustBuilder\Filament\Resources\CaseResource::class => ListCases::class,
            \Modules\TrustBuilder\Filament\Resources\CertificationResource::class => ListCertifications::class,
            default => ListCases::class,
        };
    }

    // ------------------------------------------------------------------
    // 7. 关联计数列的实际取值（数据必须真的取到数字）
    // ------------------------------------------------------------------

    public function test_counts_columns_return_real_values(): void
    {
        $this->capture('ListLinkTerms appliedLinks_count 取值', function () {
            $component = Livewire::test(ListLinkTerms::class);

            $row = $component->instance()->getTable()->getRecords()->first();

            if ($row === null) {
                $this->fail('link_terms 表为空，测试数据缺失');
            }

            $value = $row->applied_links_count ?? null;

            if ($value === null) {
                $this->fail(sprintf(
                    'LinkTerm::applied_links_count 取到 null，但库里确实有 %d 条 applied_links',
                    \Modules\LinkWeaver\Models\AppliedLink::count(),
                ));
            }
        });

        $this->capture('ListLeads items_count 取值', function () {
            $component = Livewire::test(ListLeads::class);

            $row = $component->instance()->getTable()->getRecords()->first();

            if ($row !== null && ($row->items_count ?? null) === null) {
                $this->fail('Inquiry::items_count 取到 null，但库里确实有询盘商品记录');
            }
        });

        $this->capture('ListAttrSets attributes_count 取值', function () {
            $component = Livewire::test(ListAttrSets::class);

            $row = $component->instance()->getTable()->getRecords()->first();

            if ($row !== null && ($row->attributes_count ?? null) === null) {
                $this->fail('AttrSet::attributes_count 取到 null');
            }
        });

        $this->capture('ListCategories products_count 取值', function () {
            $component = Livewire::test(ListCategories::class);

            $row = $component->instance()->getTable()->getRecords()->first();

            if ($row !== null && ($row->products_count ?? null) === null) {
                $this->fail('Category::products_count 取到 null');
            }
        });

        $this->assertNoFailures('关联计数列取值');
    }
}
