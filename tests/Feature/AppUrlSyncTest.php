<?php

namespace Tests\Feature;

use App\Filament\Widgets\AppUrlStatusWidget;
use App\Models\User;
use App\Services\AppUrlHealth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * v1.4.19 APP_URL 一键同步：宝塔「装完才开 HTTPS」场景免 SSH 手改 .env。
 * 覆盖：检测口径（协议/域名/端口）、目标地址白名单校验、.env 原子改写、
 * 端点权限（admin/editor/游客）、widget 与前台警示条的可见性。
 */
class AppUrlSyncTest extends TestCase
{
    use RefreshDatabase;

    private string $tempEnv = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    protected function tearDown(): void
    {
        AppUrlHealth::$envPath = null;

        if ($this->tempEnv !== '') {
            @unlink($this->tempEnv);
            $this->tempEnv = '';
        }

        // sync() 会改写本进程运行时配置，测试间互不污染
        config(['app.url' => 'http://localhost']);
        \Illuminate\Support\Facades\URL::forceScheme('http');

        parent::tearDown();
    }

    private function useTempEnv(string $content): string
    {
        $this->tempEnv = storage_path('app/app-url-sync-test.env');
        File::put($this->tempEnv, $content);
        AppUrlHealth::$envPath = $this->tempEnv;

        return $this->tempEnv;
    }

    // ── 检测口径 ────────────────────────────────────────────────

    public function test_inspect_ok_when_app_url_matches_request(): void
    {
        config(['app.url' => 'http://localhost']);
        $this->swapRequest('http://localhost/');
        $this->assertTrue(AppUrlHealth::inspect()['ok']);

        // 带非默认端口也必须精确匹配
        config(['app.url' => 'http://localhost:8080']);
        $this->swapRequest('http://localhost:8080/');
        $this->assertTrue(AppUrlHealth::inspect()['ok']);

        // http://x 与 http://x:80 是同一地址，不得误报
        config(['app.url' => 'http://localhost']);
        $this->swapRequest('http://localhost:80/');
        $this->assertTrue(AppUrlHealth::inspect()['ok']);
    }

    public function test_inspect_flags_scheme_mismatch(): void
    {
        // 典型现场：宝塔开启强制 HTTPS，APP_URL 还停留在安装时的 http://
        config(['app.url' => 'http://demo.test']);
        $this->swapRequest('https://demo.test/');

        $health = AppUrlHealth::inspect();

        $this->assertFalse($health['ok']);
        $this->assertSame('scheme', $health['type']);
        $this->assertSame('https://demo.test', $health['suggested']);
    }

    public function test_inspect_flags_host_mismatch(): void
    {
        // www 与非 www（本次 chinasupplier.shop 现场同款）
        config(['app.url' => 'http://demo.test']);
        $this->swapRequest('http://www.demo.test/');

        $health = AppUrlHealth::inspect();

        $this->assertFalse($health['ok']);
        $this->assertSame('host', $health['type']);
        $this->assertSame('http://www.demo.test', $health['suggested']);
    }

    // ── 目标地址校验 ────────────────────────────────────────────

    public function test_normalize_accepts_only_scheme_and_authority(): void
    {
        $valid = [
            'https://demo.test' => 'https://demo.test',
            'HTTP://Demo.TEST' => 'http://Demo.TEST',
            'https://demo.test:8443' => 'https://demo.test:8443',
            'http://192.168.1.10' => 'http://192.168.1.10',
            'http://[::1]:8080' => 'http://[::1]:8080',
        ];

        foreach ($valid as $input => $expected) {
            $this->assertSame($expected, AppUrlHealth::normalize($input), $input);
        }

        $invalid = [
            '', '   ',
            'javascript:alert(1)',
            'ftp://demo.test',
            'http://',                      // 无主机
            'http://demo.test/path',        // 带路径
            'http://demo.test?a=1',         // 带查询
            'http://demo.test#frag',        // 带片段
            'http://u:p@demo.test',         // 带凭据
            'http://demo.test:0',           // 端口越界
            'http://demo.test:99999',
            'http://demo.test:8080:90',     // 多冒号
            'http://[bad-ipv6]',
            "http://demo.test/..\\..\\ Windows 路径",
            str_repeat('a', 300),
        ];

        foreach ($invalid as $input) {
            $this->assertNull(AppUrlHealth::normalize($input), var_export($input, true));
        }
    }

    // ── .env 改写 ──────────────────────────────────────────────

    public function test_sync_rewrites_env_line_and_keeps_other_lines(): void
    {
        $path = $this->useTempEnv(
            "APP_NAME=Demo\nAPP_KEY=base64:abc\nAPP_URL=http://old.test\nAPP_ENV=production\nDB_CONNECTION=sqlite\n"
        );

        $result = AppUrlHealth::sync('https://new.test');

        $this->assertSame('ok', $result['status'], $result['message']);

        $env = (string) File::get($path);
        $this->assertStringContainsString('APP_URL=https://new.test', $env);
        $this->assertStringContainsString('APP_NAME=Demo', $env, '其他行必须原样保留');
        $this->assertStringContainsString('APP_KEY=base64:abc', $env);
        $this->assertStringContainsString('DB_CONNECTION=sqlite', $env);
        $this->assertSame(1, preg_match_all('/^APP_URL=/m', $env), '不得出现重复 APP_URL 键');
        $this->assertFileDoesNotExist($path.'.syncing', '临时文件必须清理');
    }

    public function test_sync_appends_missing_app_url_line(): void
    {
        $path = $this->useTempEnv("APP_NAME=Demo\nAPP_ENV=production\n");

        $result = AppUrlHealth::sync('https://new.test');

        $this->assertSame('ok', $result['status']);
        $this->assertSame(1, preg_match_all('/^APP_URL=https:\/\/new\.test$/m', (string) File::get($path)));
    }

    public function test_sync_rejects_invalid_url_without_touching_file(): void
    {
        $original = "APP_NAME=Demo\nAPP_URL=http://old.test\n";
        $path = $this->useTempEnv($original);

        $result = AppUrlHealth::sync('javascript:alert(1)');

        $this->assertSame('error', $result['status']);
        $this->assertStringNotContainsString('javascript', (string) File::get($path), '非法输入不得写入 .env');
    }

    public function test_sync_reports_missing_env_file(): void
    {
        AppUrlHealth::$envPath = storage_path('app/does-not-exist.env');

        $result = AppUrlHealth::sync('https://new.test');

        $this->assertSame('error', $result['status']);
    }

    // ── HTTP 端点与权限 ─────────────────────────────────────────

    public function test_endpoint_updates_env_for_admin(): void
    {
        $path = $this->useTempEnv("APP_NAME=Demo\nAPP_URL=http://old.test\n");

        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->post(route('admin.app-url.sync'), ['app_url' => 'https://new.test'])
            ->assertRedirect()
            ->assertSessionHas('app_url_sync.status', 'ok');

        $this->assertStringContainsString('APP_URL=https://new.test', (string) File::get($path));
    }

    public function test_endpoint_forbidden_for_editor(): void
    {
        $path = $this->useTempEnv("APP_NAME=Demo\nAPP_URL=http://old.test\n");

        $editor = User::factory()->create(['role' => User::ROLE_EDITOR]);

        $this->actingAs($editor)
            ->from('/admin')
            ->post(route('admin.app-url.sync'), ['app_url' => 'https://new.test'])
            ->assertForbidden();

        $this->assertStringContainsString('APP_URL=http://old.test', (string) File::get($path), '.env 必须原样保留');
    }

    public function test_endpoint_requires_login(): void
    {
        $this->useTempEnv("APP_URL=http://old.test\n");

        $this->post(route('admin.app-url.sync'), ['app_url' => 'https://new.test'])
            ->assertRedirect(route('login'));
    }

    // ── 可见性：widget 与前台警示条 ─────────────────────────────

    public function test_widget_visible_only_for_admin_on_mismatch(): void
    {
        config(['app.url' => 'http://demo.test']);
        $this->swapRequest('https://demo.test/');

        $this->assertFalse(AppUrlStatusWidget::canView(), '游客不可见');

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));
        $this->assertTrue(AppUrlStatusWidget::canView(), '不一致时管理员必须看到');

        $this->actingAs(User::factory()->create(['role' => User::ROLE_EDITOR]));
        $this->assertFalse(AppUrlStatusWidget::canView(), '编辑不可见不可点');

        config(['app.url' => 'https://demo.test']);
        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]));
        $this->assertFalse(AppUrlStatusWidget::canView(), '一致时零噪音');
    }

    public function test_front_banner_shows_for_admin_on_mismatch_only(): void
    {
        config(['app.url' => 'http://localhost']);

        // 访客完全不可见（不影响 SEO 与真实用户）——先测未登录态
        $this->get('https://localhost/')
            ->assertOk()
            ->assertDontSee('站点地址待同步');

        // 通过 https 访问（协议不一致现场）：管理员看得到提醒与一键按钮
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);

        $this->actingAs($admin)
            ->get('https://localhost/')
            ->assertOk()
            ->assertSee('站点地址待同步', false)
            ->assertSee('admin/app-url/sync', false);
    }

    /** 端到端：不一致现场仪表盘注册了告警卡片。
     *  Filament 5 的 widget 内容由前端 JS 从 Livewire 快照渲染（服务端 HTML 只含快照），
     *  故这里断言快照注册，视图内容用 Livewire::test 驱动真实组件渲染验证。 */
    public function test_dashboard_renders_sync_widget_on_mismatch(): void
    {
        config(['app.url' => 'http://other.test']);

        $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMIN]))
            ->get('/admin')
            ->assertOk()
            ->assertSee('AppUrlStatusWidget', false);

        // 真实组件渲染：站点标题 + 表单目标（相对路径 action）+ 隐藏字段全验证
        $html = \Livewire\Livewire::test(AppUrlStatusWidget::class)->html();

        $this->assertStringContainsString('站点地址（APP_URL）与实际访问不一致', $html);
        // 相对路径 action：绝不跟随 APP_URL 协议（否则坏 https 现场提交到 http:// 被浏览器拦截）
        $this->assertStringContainsString('action="/admin/app-url/sync"', $html);
        $this->assertMatchesRegularExpression('/name="app_url" value="https?:\/\/[^"]+"/', $html);
        $this->assertMatchesRegularExpression('/一键同步为 https?:\/\/[^<]+/', $html);
    }

    /**
     * 换掉容器里的当前请求：模拟通过指定 URL 访问（含 https 与端口）。
     */
    private function swapRequest(string $url): void
    {
        $this->app->instance('request', Request::create($url));
    }
}
