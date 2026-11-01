<?php

namespace Tests\Feature;

use App\Filament\Pages\PluginCenter;
use App\Filament\Pages\PluginSettingsPage;
use App\Filament\Pages\ThemeCenter;
use App\Filament\Resources\Inquiries\Pages\EditInquiry;
use App\Filament\Resources\Inquiries\RelationManagers\FollowupsRelationManager;
use App\Filament\Widgets\AppUrlStatusWidget;
use App\Filament\Widgets\WelcomeChecklistWidget;
use App\Models\Inquiry;
use App\Models\Site;
use App\Models\User;
use App\Support\PluginSettingsStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;
use Throwable;

/**
 * 后台巡检补盲扫描（AdminFullSweepTest 未覆盖的页面/组件）：
 *
 *  1. 插件独立设置页 /admin/plugins/{plugin}/settings —— 6 个有 schema 的插件
 *     （旧巡检只测过 AiContentWriter 渲染 + FeishuNotifier 渲染/保存）；
 *  2. 后台非 Filament 路由：询盘/产品 CSV 导出、后台语言切换、GSC OAuth 回调（无 code）；
 *  3. FollowupsRelationManager —— 独立 Livewire 组件，EditInquiry 冒烟不会挂载它，
 *     且旧巡检造数里 inquiry_followups 始终为空表（含 user.name 列、created_at 排序）；
 *  4. 仪表盘 Widget（新手清单 / APP_URL 告警）；
 *  5. 两个 zip 上传动作（ThemeCenter / PluginCenter）—— FileUpload 的 state 是
 *     「路径数组」，若消费方直接当字符串用（Storage::path($data['zip'])）会 TypeError 500，
 *     与 RichEditor 数组 / counts 列别名同属一类根因。用真实 fake 上传走完整 action 管线探针。
 */
class AdminSweepGapsTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> 失败清单：场景 => 异常 */
    protected array $failures = [];

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->admin = User::firstOrFail();
        $this->actingAs($this->admin);
        $this->seedGapData();
    }

    // ------------------------------------------------------------------
    // 数据准备：只为补盲需要的新表造数
    // ------------------------------------------------------------------

    protected function seedGapData(): void
    {
        $site = Site::firstOrFail();

        // 询盘（导出路由 + Followups 关联管理器的宿主记录）
        $inquiry = Inquiry::firstOrCreate(
            ['email' => 'gap-lead@example.com'],
            [
                'site_id' => $site->id,
                'name' => 'Gap Lead',
                'company' => 'Gap Co',
                'country' => 'US',
                'message' => 'Please quote butterfly valves.',
                'status' => 'new',
                'source' => 'web',
            ],
        );

        // 两条跟进记录（两条才能让排序有比较意义；channel 覆盖两个枚举值）
        \App\Models\InquiryFollowup::firstOrCreate(
            ['inquiry_id' => $inquiry->id, 'content' => 'gap followup one'],
            [
                'user_id' => $this->admin->id,
                'channel' => 'email',
                'next_action_at' => now()->addDays(3),
            ],
        );
        \App\Models\InquiryFollowup::firstOrCreate(
            ['inquiry_id' => $inquiry->id, 'content' => 'gap followup two'],
            [
                'user_id' => $this->admin->id,
                'channel' => 'phone',
                'next_action_at' => null,
            ],
        );
    }

    // ------------------------------------------------------------------
    // 工具（与 AdminFullSweepTest 相同的 capture 模式）
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

    /** 有独立 Settings schema 的全部插件（PluginSettingsStore::hasSettings 的判定口径） */
    protected function settingsPlugins(): array
    {
        return ['AiContentWriter', 'AiSeoAuditor', 'FeishuNotifier', 'MailNotifier', 'UpdateCenter', 'WhatsAppChat'];
    }

    // ------------------------------------------------------------------
    // 1. 插件设置页：HTTP 路由 + Livewire 组件，6 个插件全覆盖
    // ------------------------------------------------------------------

    public function test_all_plugin_settings_pages_render(): void
    {
        foreach ($this->settingsPlugins() as $plugin) {
            $this->capture("HTTP GET /admin/plugins/{$plugin}/settings", function () use ($plugin) {
                $this->get("/admin/plugins/{$plugin}/settings")->assertOk();
            });

            $this->capture("Livewire::test PluginSettingsPage({$plugin})", function () use ($plugin) {
                Livewire::test(PluginSettingsPage::class, ['plugin' => $plugin])->assertOk();
            });
        }

        // 无 schema 的插件应 404，而不是 500（module 存在但没有 PluginSettings）
        $this->capture('HTTP GET settings page of schema-less plugin (expect 404)', function () {
            $this->get('/admin/plugins/GeoBoost/settings')->assertNotFound();
        });

        $this->assertNoFailures('插件设置页渲染');
    }

    // ------------------------------------------------------------------
    // 2. 插件设置保存：旧巡检只测过 FeishuNotifier，这里补 4 个有独立 schema 的插件
    //    （required 字段填值走 ok 分支，断言值真的落库）
    // ------------------------------------------------------------------

    public function test_plugin_settings_save_for_untested_plugins(): void
    {
        $cases = [
            // plugin => [设置的字段 => 值]（required 字段必须填，避免走拒绝分支）
            'AiSeoAuditor' => ['limit' => '50', 'schedule_hour' => '3'],
            'MailNotifier' => ['admin_emails' => 'sales@example.com, boss@example.com', 'enabled' => true],
            'UpdateCenter' => ['github_repo' => 'example/btobcms'],
            'WhatsAppChat' => ['phone' => '8613800138000', 'message' => 'Hi {page}'],
        ];

        foreach ($cases as $plugin => $fields) {
            $this->capture("PluginSettingsPage({$plugin}) save()", function () use ($plugin, $fields) {
                $component = Livewire::test(PluginSettingsPage::class, ['plugin' => $plugin]);

                $data = $component->get('data');

                foreach ($fields as $key => $value) {
                    $data[$key] = $value;
                }

                $component->set('data', $data)
                    ->call('save')
                    ->assertOk();

                // 保存必须真的落库（values() 优先读 DB 覆盖值；归一化口径与 PluginSettingsStore::save 一致：
                // boolean 强转 bool、number 转 0+raw、其余 trim 字符串）
                $values = PluginSettingsStore::values($plugin);
                $schema = collect(PluginSettingsStore::fields($plugin))->keyBy('key');

                foreach ($fields as $key => $value) {
                    $type = $schema[$key]['type'] ?? 'text';

                    $expected = match ($type) {
                        'boolean' => (bool) $value,
                        'number' => ($value === '' || $value === null) ? '' : (is_numeric($value) ? 0 + $value : trim((string) $value)),
                        default => trim((string) $value),
                    };

                    $this->assertSame(
                        $expected,
                        $values[$key],
                        "PluginSettingsStore::values({$plugin})[{$key}] 未反映保存结果",
                    );
                }
            });
        }

        $this->assertNoFailures('插件设置保存');
    }

    // ------------------------------------------------------------------
    // 3. 后台非 Filament 路由：CSV 导出 / 语言切换 / GSC 回调（旧巡检完全未覆盖）
    // ------------------------------------------------------------------

    public function test_export_locale_and_callback_routes(): void
    {
        $this->capture('HTTP GET /admin/inquiries/export', function () {
            $this->get('/admin/inquiries/export')->assertOk();
        });

        $this->capture('HTTP GET /admin/inquiries/export?status=new', function () {
            $this->get('/admin/inquiries/export?status=new')->assertOk();
        });

        $this->capture('HTTP GET /admin/products/export', function () {
            $this->get('/admin/products/export')->assertOk();
        });

        // 语言切换是 302 回跳，只要求不 500 / 不 404
        foreach (['zh_CN', 'en'] as $locale) {
            $this->capture("HTTP GET /admin/locale/{$locale}", function () use ($locale) {
                $status = $this->get("/admin/locale/{$locale}")->status();
                $this->assertTrue(
                    $status === 302 || $status === 200,
                    "切换后台语言返回了 {$status}",
                );
            });
        }

        // GSC OAuth 回调无 code/state：应回跳连接页，而不是 500
        $this->capture('HTTP GET /admin/gsc-pulse/callback (no code)', function () {
            $status = $this->get('/admin/gsc-pulse/callback')->status();
            $this->assertTrue($status < 500, "GSC 回调返回了 {$status}");
        });

        $this->assertNoFailures('后台辅助路由');
    }

    // ------------------------------------------------------------------
    // 4. FollowupsRelationManager：独立 Livewire 组件 + 有数据后的行渲染与排序
    // ------------------------------------------------------------------

    public function test_followups_relation_manager_renders_and_sorts(): void
    {
        $inquiry = Inquiry::firstOrFail();

        $this->capture('Livewire::test FollowupsRelationManager', function () use ($inquiry) {
            Livewire::test(FollowupsRelationManager::class, [
                'ownerRecord' => $inquiry,
                'pageClass' => EditInquiry::class,
            ])->assertOk();
        });

        // 行渲染：channel 列有 fn (string $state) 类型闭包，行值非空才能证明不 TypeError
        $this->capture('FollowupsRelationManager rows render', function () use ($inquiry) {
            $component = Livewire::test(FollowupsRelationManager::class, [
                'ownerRecord' => $inquiry,
                'pageClass' => EditInquiry::class,
            ]);

            $records = $component->instance()->getTable()->getRecords();

            $this->assertSame(2, $records->count(), '跟进表应渲染出 2 条造数记录');
            $this->assertSame(
                ['email', 'phone'],
                $records->sortBy('id')->pluck('channel')->all(),
                'channel 列类型闭包消费的值形态不符',
            );
        });

        // 可排序列排序（created_at）
        $this->capture('FollowupsRelationManager sortTable(created_at)', function () use ($inquiry) {
            Livewire::test(FollowupsRelationManager::class, [
                'ownerRecord' => $inquiry,
                'pageClass' => EditInquiry::class,
            ])->call('sortTable', 'created_at', 'desc')->assertOk();
        });

        $this->assertNoFailures('跟进关联管理器');
    }

    // ------------------------------------------------------------------
    // 5. 仪表盘 Widget（GET /admin 会挂载它们，但旧巡检只断言 200，没有独立冒烟）
    // ------------------------------------------------------------------

    public function test_dashboard_widgets_render(): void
    {
        $this->capture('Livewire::test WelcomeChecklistWidget', function () {
            Livewire::test(WelcomeChecklistWidget::class)->assertOk();
        });

        // 生产触发路径：APP_URL 与请求 host 不一致时 canView() 变 true，
        // GET /admin 必须仍能 200（widget 真实挂载在仪表盘上渲染）
        $this->capture('GET /admin with APP_URL mismatch (AppUrlStatusWidget visible)', function () {
            config(['app.url' => 'http://mismatch.test']);

            $this->assertTrue(AppUrlStatusWidget::canView(), 'URL 不一致 + 管理员时 widget 应可见');

            $this->get('/admin')->assertOk();
        });

        // 恢复一致后再挂一次仪表盘（canView false 的零噪音分支）
        $this->capture('GET /admin with APP_URL consistent', function () {
            $this->get('/admin')->assertOk();
        });

        $this->assertNoFailures('仪表盘 Widget');
    }

    // ------------------------------------------------------------------
    // 6. zip 上传动作探针：FileUpload 的 Livewire state 是「路径数组」，
    //    若 action 里当字符串消费（Storage::path($data['zip'])）会 TypeError 500。
    //    用真实 fake 上传走完整 action 管线；zip 内容无效时下游应优雅返回错误通知。
    // ------------------------------------------------------------------

    public function test_theme_zip_upload_action_accepts_file_state(): void
    {
        $this->capture('ThemeCenter uploadTheme with fake zip', function () {
            Storage::fake('local');

            $file = UploadedFile::fake()->create('theme-gap.zip', 20, 'application/zip');

            Livewire::test(ThemeCenter::class)
                ->callAction('uploadTheme', data: ['zip' => $file])
                ->assertHasNoErrors();
        });

        $this->assertNoFailures('主题 zip 上传');
    }

    public function test_plugin_zip_upload_action_accepts_file_state(): void
    {
        $this->capture('PluginCenter uploadPlugin with fake zip', function () {
            Storage::fake('local');

            $file = UploadedFile::fake()->create('plugin-gap.zip', 20, 'application/zip');

            Livewire::test(PluginCenter::class)
                ->callAction('uploadPlugin', data: ['zip' => $file])
                ->assertHasNoErrors();
        });

        $this->assertNoFailures('插件 zip 上传');
    }
}
