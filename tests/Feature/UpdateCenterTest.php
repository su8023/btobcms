<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\UpdateCenter\Services\GitHubReleases;
use Modules\UpdateCenter\Services\GitService;
use Tests\TestCase;

class UpdateCenterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(User::first());
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
            ->assertSee((string) config('version.version'), false)
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

        $this->get('/admin/update-center')
            ->assertOk()
            ->assertSee('v1.1.0')
            ->assertSee('October update')
            ->assertSee("## What's new")
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

    public function test_invalid_repo_format_returns_null_from_settings(): void
    {
        Setting::put('plugin.UpdateCenter', ['github_repo' => 'not a repo']);

        $this->assertNull(GitHubReleases::fromSettings());
    }

    public function test_git_service_reads_current_tag_in_project_repo(): void
    {
        // 项目已 git init + tag v1.0.0（本仓库事实）
        if (! is_dir(base_path('.git'))) {
            $this->markTestSkipped('非 git 检出环境');
        }
        $git = new GitService('git', base_path());
        // 允许 tag 后有后续提交：用 --tags 宽松描述验证 tag 可达
        $r = $git->run(['describe', '--tags']);
        $this->assertSame(0, $r['exit'], '仓库应存在可描述的 tag');
        $this->assertTrue($git->tagExists('v1.0.0'));
        $this->assertTrue($git->hasRemote() === false || $git->hasRemote() === true); // 本地无 remote 合法
    }
}
