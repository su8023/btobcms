<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class InstallWizardTest extends TestCase
{
    use RefreshDatabase;

    private string $envBackup = '';
    private string $lockPath = '';
    private string $testDb = '';

    protected function setUp(): void
    {
        // 备份 .env 必须在 parent::setUp() 之前（app 容器尚未启动，用纯路径）：
        // 若 RefreshDatabase 在 parent 内崩溃，空备份会被 tearDown 写回清空 .env
        $this->envBackup = (string) file_get_contents(__DIR__.'/../../.env');

        parent::setUp();

        $this->lockPath = storage_path('app/installed.lock');
        $this->testDb = storage_path('app/install-test.sqlite');

        File::delete($this->lockPath);
        File::delete($this->testDb);
    }

    protected function tearDown(): void
    {
        // 仅在拿到有效备份时还原（防 setUp 中途崩溃把空串写回清空 .env）
        if (strlen($this->envBackup) > 10) {
            File::put(base_path('.env'), $this->envBackup);
        }
        File::delete($this->lockPath);
        File::delete($this->testDb);

        // 清理安装器可能留下的运行时连接与配置缓存（config:cache 类命令会重载进程配置）
        try {
            $this->app['db']->purge('install_run');
            $this->app['db']->purge('install_check');
        } catch (\Throwable) {
        }
        File::delete(base_path('bootstrap/cache/config.php'));

        parent::tearDown();
    }

    public function test_wizard_shows_environment_checks_when_not_installed(): void
    {
        $this->get('/install')
            ->assertOk()
            ->assertSee('环境检测')
            ->assertSee('目录可写 storage')
            ->assertSee('BtoBcms 安装向导', false);
    }

    public function test_full_install_flow_creates_env_admin_and_lock(): void
    {
        $response = $this->post('/install', [
            'db_connection' => 'sqlite',
            'db_database' => 'storage/app/install-test.sqlite',
            'app_name' => 'Test Factory Supply',
            'admin_name' => 'Boss',
            'admin_email' => 'boss@example.com',
            'admin_password' => 'Secret@12345',
        ]);

        $response->assertRedirect('/install/done');
        $this->get('/install/done')->assertOk()->assertSee('安装完成');

        // .env 生成且带关键生产配置；重复键会让 dotenv（不可变模式）取首值导致配置失效
        $env = File::get(base_path('.env'));
        $this->assertStringContainsString('APP_ENV=production', $env);
        $this->assertStringContainsString('APP_DEBUG=false', $env);
        $this->assertStringContainsString('APP_KEY=base64:', $env);
        $this->assertStringContainsString('DB_CONNECTION=sqlite', $env);
        $this->assertStringContainsString('APP_NAME="Test Factory Supply"', $env);
        $this->assertSame(1, preg_match_all('/^APP_KEY=/m', $env), 'APP_KEY 不得出现重复键');
        $this->assertSame(1, preg_match_all('/^APP_NAME=/m', $env), 'APP_NAME 不得出现重复键');
        $this->assertSame(1, preg_match_all('/^DB_DATABASE=/m', $env), 'DB_DATABASE 不得出现重复键');
        $this->assertStringNotContainsString("APP_KEY=\n", $env, 'APP_KEY 不得为空值');

        // 安装锁 + 管理员账号（写入独立 install_run 连接，断言同连接）
        $this->assertFileExists($this->lockPath);
        $this->assertTrue(
            \DB::connection('install_run')->table('users')->where('email', 'boss@example.com')->exists()
        );

        // 已安装后所有入口关闭
        $this->get('/install')->assertNotFound();
        $this->post('/install', [])->assertNotFound();
        $this->get('/install/done')->assertOk(); // done 页保留作完成回看
    }

    public function test_rejects_non_empty_database(): void
    {
        // 预置一个非空 sqlite 文件
        File::put($this->testDb, 'not empty');

        $this->from('/install')->post('/install', [
            'db_connection' => 'sqlite',
            'db_database' => 'storage/app/install-test.sqlite',
            'app_name' => 'X',
            'admin_name' => 'Y',
            'admin_email' => 'y@example.com',
            'admin_password' => 'Secret@12345',
        ])->assertSessionHasErrors('db_database');

        $this->assertFileDoesNotExist($this->lockPath);
    }

    public function test_validates_required_fields(): void
    {
        $this->from('/install')->post('/install', [
            'db_connection' => 'mysql',
            'db_database' => '',
        ])->assertSessionHasErrors(['db_database', 'db_host', 'db_username', 'app_name', 'admin_email', 'admin_password']);
    }

    public function test_env_quotes_special_char_credentials(): void
    {
        // 宝塔随机密码常见杀手字符：# 空格 $ 引号 反斜杠——裸写进 .env 会被
        // dotenv 当注释截断或解析报错，装完即数据库连不上
        $password = 'p@ss #1 $"x" \\end';

        $this->post('/install', [
            'db_connection' => 'sqlite',
            'db_database' => 'storage/app/install-test.sqlite',
            'db_password' => $password,
            'app_name' => 'X "Quoted" Co',
            'admin_name' => 'Boss',
            'admin_email' => 'boss2@example.com',
            'admin_password' => 'Secret@12345',
        ])->assertRedirect('/install/done');

        $parsed = \Dotenv\Dotenv::parse(File::get(base_path('.env')));
        $this->assertSame($password, $parsed['DB_PASSWORD'] ?? null, '特殊字符密码必须无损写入 .env');
        $this->assertSame('X "Quoted" Co', $parsed['APP_NAME'] ?? null, '含引号的站点名必须无损写入 .env');
    }
}
