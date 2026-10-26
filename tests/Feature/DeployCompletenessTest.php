<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * 一键部署完整性：安装完成后所有功能开箱即用，不需要任何 SSH 后置操作。
 */
class DeployCompletenessTest extends TestCase
{
    public function test_livewire_assets_are_published_as_real_static_files(): void
    {
        // 登录页依赖的 JS 必须是真实静态文件（200），
        // 而不是生产模式 Livewire 的 404 状态动态路由（部分浏览器拒绝执行 → 登录按钮无响应）
        $this->assertFileExists(public_path('vendor/livewire/livewire.min.js'));
        $this->assertFileExists(public_path('vendor/livewire/livewire.js'));

        // 本地静态文件与 vendor dist 内容一致
        $this->assertSame(
            md5_file(public_path('vendor/livewire/livewire.min.js')),
            md5_file(base_path('vendor/livewire/livewire/dist/livewire.min.js')),
        );
    }

    public function test_env_example_defaults_are_deploy_safe(): void
    {
        // 一键部署最稳默认：file 会话/缓存（无外部依赖、不依赖任何后置命令）
        $env = (string) file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('SESSION_DRIVER=file', $env);
        $this->assertStringContainsString('CACHE_STORE=file', $env);
    }

    public function test_installer_publishes_assets_during_install(): void
    {
        // 安装控制器必须在安装流程内自动发布资源（不给用户留 SSH 后置步骤）
        $code = (string) file_get_contents(app_path('Http/Controllers/InstallController.php'));

        $this->assertStringContainsString("vendor:publish", $code);
        $this->assertStringContainsString("livewire:assets", $code);
        $this->assertStringContainsString("storage:link", $code);
        $this->assertStringContainsString("migrate", $code);
    }
}
