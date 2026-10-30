<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\UpdateCenter\Services\GitHubReleases;
use Tests\TestCase;

class UpdateCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(User::first());
        // array 缓存在同一 PHPUnit 进程内跨测试残留，会污染 releases 缓存用例
        cache()->flush();
    }

    protected function tearDown(): void
    {
        @unlink(storage_path('app/update-center.log'));
        parent::tearDown();
    }

    public function test_module_structure_declares_category_and_provider(): void
    {
        $json = json_decode((string) file_get_contents(module_path('UpdateCenter', 'module.json')), true);

        $this->assertSame('UpdateCenter', $json['name']);
        $this->assertSame('system', $json['category']);
        $this->assertContains('Modules\\UpdateCenter\\Providers\\UpdateCenterServiceProvider', $json['providers']);
    }

    public function test_page_renders_with_current_version_when_not_configured(): void
    {
        $this->get('/admin/update-center')
            ->assertOk()
            ->assertSee(__('admin.update_center.not_configured'));
    }

    public function test_page_shows_latest_release_and_notes_when_configured(): void
    {
        Setting::put('plugin.UpdateCenter', [
            'github_repo' => 'acme/btobcms',
            'github_token' => '',
        ]);

        Http::fake([
            'https://api.github.com/repos/acme/btobcms/releases*' => Http::response([
                ['tag_name' => 'v1.1.0', 'name' => 'October update', 'published_at' => '2026-10-26T00:00:00Z',
                 'body' => "## What's new\n- Faster hero\n- New industries", 'html_url' => 'https://github.com/acme/btobcms/releases/tag/v1.1.0',
                 'prerelease' => false],
                ['tag_name' => 'v1.0.0', 'name' => 'First release', 'published_at' => '2026-10-24T00:00:00Z',
                 'body' => 'Initial', 'html_url' => '', 'prerelease' => false],
            ], 200),
        ]);

        // 当前版本来自 config/version.php（发布包自带，随更新自动到位）
        $this->get('/admin/update-center')
            ->assertOk()
            ->assertSee(config('version.version'))
            ->assertSee('v1.1.0')
            ->assertSee('October update')
            ->assertSee(__('admin.update_center.update_available'));
    }

    public function test_github_client_skips_prerelease_for_latest(): void
    {
        Http::fake([
            'https://api.github.com/repos/acme/btobcms/releases*' => Http::response([
                ['tag_name' => 'v2.0.0-rc1', 'name' => 'RC', 'body' => '', 'html_url' => '', 'prerelease' => true],
                ['tag_name' => 'v1.2.0', 'name' => 'Stable', 'body' => '', 'html_url' => '', 'prerelease' => false],
            ], 200),
        ]);

        $this->assertSame('v1.2.0', (new GitHubReleases('acme/btobcms'))->latest()['tag'] ?? null);
    }

    public function test_github_client_reports_rate_limit_as_error(): void
    {
        Http::fake([
            'https://api.github.com/*' => Http::response([], 403),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('限流');
        (new GitHubReleases('acme/btobcms'))->releases();
    }

    /** 发版后盲区修复：缓存显示「已是最新」时按节流回源，看到远端新版本 */
    public function test_releases_refreshes_when_cached_latest_matches_current_version(): void
    {
        config(['version.version' => 'v1.1.0']);
        $latest = 'v1.1.0';

        Http::fake(function () use (&$latest) {
            return Http::response([
                ['tag_name' => $latest, 'name' => 'Rel', 'body' => '', 'html_url' => '', 'prerelease' => false],
            ], 200);
        });
        (new GitHubReleases('acme/btobcms'))->releases(); // 预热缓存：latest == 当前版本

        $latest = 'v1.2.0';

        $this->assertSame('v1.2.0', (new GitHubReleases('acme/btobcms'))->releases(refreshIfUpToDate: true)[0]['tag']);
    }

    /** 节流窗口内不重复回源（保护匿名 60 次/小时限流） */
    public function test_releases_refresh_is_throttled_within_window(): void
    {
        config(['version.version' => 'v1.1.0']);
        $key = 'update-center.releases.'.md5('acme/btobcms|');
        $latest = 'v1.1.0';

        Http::fake(function () use (&$latest) {
            return Http::response([
                ['tag_name' => $latest, 'name' => 'Rel', 'body' => '', 'html_url' => '', 'prerelease' => false],
            ], 200);
        });
        (new GitHubReleases('acme/btobcms'))->releases(refreshIfUpToDate: true); // 预热缓存
        cache()->put($key.'.checked', true, now()->addMinutes(5)); // 节流窗口已被占用（本次已回源过）

        // 缓存重新落回「已是最新」状态，远端已悄悄更新
        cache()->put($key, [
            ['tag' => 'v1.1.0', 'name' => 'Rel', 'date' => null, 'notes' => '', 'url' => '', 'prerelease' => false],
        ], now()->addMinutes(30));
        $latest = 'v1.2.0';

        // 节流窗口内：不回源，仍返回缓存里的 v1.1.0
        $this->assertSame('v1.1.0', (new GitHubReleases('acme/btobcms'))->releases(refreshIfUpToDate: true)[0]['tag']);
    }

    public function test_invalid_repo_format_returns_null_from_settings(): void
    {
        Setting::put('plugin.UpdateCenter', ['github_repo' => 'not a repo']);

        $this->assertNull(GitHubReleases::fromSettings());
    }

    public function test_container_resolves_updater_with_configured_repo(): void
    {
        // 回归：容器解析 SelfUpdater 时必须带插件设置里的 repo/token，
        // 否则更新会误报「未配置 GitHub 仓库」
        Setting::put('plugin.UpdateCenter', [
            'github_repo' => 'su8023/btobcms',
            'github_token' => 'gho_test',
        ]);

        $updater = app(\Modules\UpdateCenter\Services\SelfUpdater::class);

        $reflection = new \ReflectionProperty($updater, 'repo');
        $reflection->setAccessible(true);

        $this->assertSame('su8023/btobcms', $reflection->getValue($updater));
    }

    public function test_updater_preflight_has_no_git_or_composer_dependency(): void
    {
        // WordPress 式自更新只依赖自身能力（zip 扩展/可写目录），不再要求 git 与 composer
        $problems = \Modules\UpdateCenter\Services\SelfUpdater::preflight();

        $this->assertSame([], $problems);
    }
}
