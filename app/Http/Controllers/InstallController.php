<?php

namespace App\Http\Controllers;

use App\Models\User;
use Artisan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

/**
 * Web 安装向导（宝塔/任意主机一键安装）：
 *   GET  /install        环境自检（PHP 版本/扩展/目录权限）
 *   POST /install        提交数据库+管理员配置 → 写 .env → 迁移 → 建管理员 → 加安装锁
 *   GET  /install/done   完成页
 * storage/app/installed.lock 存在即视为已安装，所有入口 404（防重装/防接管）。
 */
class InstallController extends Controller
{
    private const LOCK = 'app/installed.lock';

    public function index()
    {
        if ($this->installed()) {
            abort(404);
        }

        return view('install.wizard', ['step' => 'check', 'checks' => $this->environmentChecks()]);
    }

    public function store(Request $request): RedirectResponse
    {
        if ($this->installed()) {
            abort(404);
        }

        $data = $request->validate([
            'db_connection' => ['required', 'in:mysql,sqlite'],
            // nullable 前置：HTTP 中间件会把空输入转 null（sqlite 时这些字段不填）
            'db_host' => ['nullable', 'required_unless:db_connection,sqlite', 'string', 'max:191'],
            'db_port' => ['nullable', 'required_unless:db_connection,sqlite', 'integer', 'between:1,65535'],
            'db_database' => ['required', 'string', 'max:191'],
            'db_username' => ['nullable', 'required_unless:db_connection,sqlite', 'string', 'max:191'],
            'db_password' => ['nullable', 'string', 'max:191'],
            'app_name' => ['required', 'string', 'max:64'],
            'admin_name' => ['required', 'string', 'max:64'],
            'admin_email' => ['required', 'email', 'max:191'],
            'admin_password' => ['required', 'string', 'min:8', 'max:64'],
            'seed_demo' => ['nullable', 'boolean'],
        ]);

        $db = [
            'connection' => $data['db_connection'],
            'host' => $data['db_host'] ?? '127.0.0.1',
            'port' => (int) ($data['db_port'] ?? 3306),
            'database' => $data['db_database'],
            'username' => $data['db_username'] ?? '',
            'password' => (string) ($data['db_password'] ?? ''),
        ];

        // 安装涉及建表/种子等长操作：放宽本请求执行时限（CLI-server/FPM 默认 30s 可能不够）
        @set_time_limit(600);

        // 1. 数据库连通性（运行时建临时连接，不动主配置）
        if (($error = $this->testDatabase($db)) !== null) {
            return back()->withInput()->withErrors(['db_database' => $error]);
        }

        // 2. 写 .env（以 .env.example 为模板；无模板时用内置最小模板）
        $this->writeEnvironment($data, $db);

        // 3. 建独立运行时连接执行安装（不覆盖默认连接，避免影响当前会话/队列）
        $conn = $this->makeRuntimeConnection($db);

        // 4. 迁移 + （可选）演示数据 + 管理员
        try {
            Artisan::call('migrate', ['--force' => true, '--database' => $conn]);
        } catch (Throwable $e) {
            return back()->withInput()->withErrors(['db_database' => '数据表迁移失败：'.$e->getMessage()]);
        }

        if (! empty($data['seed_demo'])) {
            Artisan::call('db:seed', ['--force' => true, '--database' => $conn, '--class' => 'DemoContentSeeder']);
        }

        User::on($conn)->firstOrCreate(
            ['email' => $data['admin_email']],
            [
                'name' => $data['admin_name'],
                'password' => $data['admin_password'],
                'email_verified_at' => now(),
            ],
        );
        try {
            DB::purge('install_run');
        } catch (Throwable) {
        }

        if (! is_link(public_path('storage'))) {
            try {
                Artisan::call('storage:link');
            } catch (Throwable) {
                // 部分 Windows/受限主机软链失败，提示手工处理即可
            }
        }

        // 注：不在请求内做 config/route/view 缓存（会重载当前进程配置）。
        // 完成页引导 SSH 执行 php artisan optimize。

        File::put(storage_path(self::LOCK), json_encode([
            'installed_at' => now()->toIso8601String(),
            'app_url' => config('app.url'),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return redirect()->to('/install/done');
    }

    public function done()
    {
        if (! $this->installed()) {
            abort(404);
        }

        return view('install.wizard', ['step' => 'done']);
    }

    /**
     * 断点续装判定：SQLite 文件里有 migrations 进度但尚无 users 表
     * （上次安装中途断开，migrate 幂等可继续）。
     */
    private function isResumableSqlite(string $path): bool
    {
        try {
            $pdo = new \PDO('sqlite:'.$path);
            $migrations = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='migrations'")->fetch();

            return $migrations !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    private function installed(): bool
    {
        return File::exists(storage_path(self::LOCK));
    }

    /**
     * @return array<int, array{label: string, pass: bool, hint: string}>
     */
    private function environmentChecks(): array
    {
        $requiredExtensions = ['pdo', 'mbstring', 'openssl', 'fileinfo', 'gd', 'curl', 'zip', 'intl', 'bcmath', 'tokenizer', 'xml', 'ctype', 'json'];
        $checks = [
            ['label' => 'PHP 版本 ≥ 8.2', 'pass' => version_compare(PHP_VERSION, '8.2.0', '>='), 'hint' => '当前 '.PHP_VERSION],
            ['label' => 'Composer 依赖已安装（vendor 目录）', 'pass' => is_dir(base_path('vendor')), 'hint' => '未安装时执行 composer install --no-dev'],
        ];

        foreach ($requiredExtensions as $ext) {
            $checks[] = ['label' => "扩展 {$ext}", 'pass' => extension_loaded($ext), 'hint' => '宝塔：PHP 设置 → 安装扩展'];
        }

        foreach (['storage', 'storage/app', 'storage/framework', 'storage/logs', 'bootstrap/cache'] as $dir) {
            $checks[] = [
                'label' => "目录可写 {$dir}",
                'pass' => is_dir(base_path($dir)) && is_writable(base_path($dir)),
                'hint' => 'chmod -R 775 '.$dir,
            ];
        }

        $checks[] = ['label' => '.env.example 模板存在', 'pass' => File::exists(base_path('.env.example')), 'hint' => '缺失则向导使用内置模板'];
        $checks[] = ['label' => '禁用函数已放开（proc_open）', 'pass' => function_exists('proc_open'), 'hint' => '宝塔：PHP 设置 → 禁用函数 中删除'];

        return $checks;
    }

    /**
     * 连通性测试：返回 null=通过，否则错误消息。
     */
    private function testDatabase(array $db): ?string
    {
        try {
            if ($db['connection'] === 'sqlite') {
                $path = $db['database'] === ':memory:' ? ':memory:' : $db['database'];
                if ($path !== ':memory:' && ! str_starts_with($path, '/') && ! preg_match('/^[A-Z]:[\\\\\/]/i', $path)) {
                    $path = base_path($path);
                }
                if ($path !== ':memory:') {
                    if (! File::exists($path)) {
                        if (! File::exists(dirname($path))) {
                            File::makeDirectory(dirname($path), 0775, true);
                        }
                        File::put($path, '');
                    } elseif (filesize($path) > 0) {
                        // 半装库（有 migrations 进度但未建管理员）允许断点续装，其余拒绝
                        if (! $this->isResumableSqlite($path)) {
                            return 'SQLite 文件已存在且非空：'.$path;
                        }
                    }
                }
                config(['database.connections.install_check' => [
                    'driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true,
                ]]);
            } else {
                config(['database.connections.install_check' => [
                    'driver' => 'mysql',
                    'host' => $db['host'], 'port' => $db['port'],
                    'database' => $db['database'], 'username' => $db['username'], 'password' => $db['password'],
                    'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '',
                ]]);
            }

            DB::purge('install_check');
            DB::connection('install_check')->select('select 1');

            // MySQL：确认库为空（防误装到旧库）；半装库（有迁移进度无 users）允许续装
            if ($db['connection'] === 'mysql') {
                $hasUsers = DB::connection('install_check')->select("show tables like 'users'");
                $hasMigrations = DB::connection('install_check')->select("show tables like 'migrations'");
                if ($hasUsers !== [] && $hasMigrations === []) {
                    return '该数据库已存在 users 表（可能装过），请换空库或先备份清空';
                }
            }

            return null;
        } catch (Throwable $e) {
            return '数据库连接失败：'.$e->getMessage();
        } finally {
            try {
                DB::purge('install_check');
            } catch (Throwable) {
            }
        }
    }

    private function writeEnvironment(array $data, array $db): void
    {
        $template = File::exists(base_path('.env.example'))
            ? File::get(base_path('.env.example'))
            : "APP_NAME=Laravel\nAPP_ENV=local\nAPP_KEY=\nAPP_DEBUG=true\nAPP_URL=http://localhost\n\nDB_CONNECTION=sqlite\n\nSESSION_DRIVER=database\nQUEUE_CONNECTION=database\nCACHE_STORE=database\n\nMAIL_MAILER=log\n";

        $scheme = request()->isSecure() ? 'https' : 'http';
        $appUrl = $scheme.'://'.request()->getHost();

        $appName = str_replace(['"', "'"], '', $data['app_name']);
        if (str_contains($appName, ' ')) {
            $appName = '"'.$appName.'"';
        }

        // SQLite 必须写绝对路径：不同请求/进程的 CWD 不一致时相对路径直接连不上
        $dbDatabase = $db['database'];
        if ($db['connection'] === 'sqlite'
            && $dbDatabase !== ':memory:'
            && ! str_starts_with($dbDatabase, '/')
            && ! preg_match('/^[A-Z]:[\\\\\/]/i', $dbDatabase)) {
            $dbDatabase = str_replace('\\', '/', base_path($dbDatabase));
        }

        // 键不带等号（与下方行内正则捕获组一致），避免重复键：dotenv 不可变模式下首值生效
        $replacements = [
            'APP_NAME' => 'APP_NAME='.$appName,
            'APP_ENV' => 'APP_ENV=production',
            'APP_KEY' => 'APP_KEY=base64:'.base64_encode(random_bytes(32)),
            'APP_DEBUG' => 'APP_DEBUG=false',
            'APP_URL' => 'APP_URL='.$appUrl,
            'APP_TIMEZONE' => 'APP_TIMEZONE=Asia/Shanghai',
            'DB_CONNECTION' => 'DB_CONNECTION='.$db['connection'],
            'DB_HOST' => 'DB_HOST='.$db['host'],
            'DB_PORT' => 'DB_PORT='.$db['port'],
            'DB_DATABASE' => 'DB_DATABASE='.$dbDatabase,
            'DB_USERNAME' => 'DB_USERNAME='.$db['username'],
            'DB_PASSWORD' => 'DB_PASSWORD='.$db['password'],
        ];

        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', $template) as $line) {
            if (preg_match('/^([A-Z0-9_]+)=(.*)$/', $line, $m) && isset($replacements[$m[1]])) {
                $lines[] = $replacements[$m[1]];
                unset($replacements[$m[1]]);
            } elseif (preg_match('/^# (DB_HOST|DB_PORT|DB_DATABASE|DB_USERNAME|DB_PASSWORD|APP_TIMEZONE)=/', $line)) {
                continue; // 去掉注释掉的 DB 键，避免与新值并存
            } else {
                $lines[] = $line;
            }
        }
        // 模板中没有的键追加到对应位置（简单追加尾部）
        foreach ($replacements as $key => $value) {
            if (str_starts_with($key, 'APP_TIMEZONE')) {
                continue;
            }
            $lines[] = $value;
        }
        if (! in_array('APP_TIMEZONE=Asia/Shanghai', $lines, true)) {
            // 插在 APP_URL 行后
            $idx = null;
            foreach ($lines as $i => $l) {
                if (str_starts_with($l, 'APP_URL=')) {
                    $idx = $i;
                    break;
                }
            }
            array_splice($lines, (int) $idx + 1, 0, ['APP_TIMEZONE=Asia/Shanghai']);
        }

        File::put(base_path('.env'), implode("\n", $lines)."\n");
    }

    /**
     * 建独立的运行时安装连接（install_run），返回连接名。
     * 不修改默认连接：安装失败时站点当前会话不受影响。
     */
    private function makeRuntimeConnection(array $db): string
    {
        if ($db['connection'] === 'sqlite') {
            $path = $db['database'];
            if (! str_starts_with($path, '/') && ! preg_match('/^[A-Z]:[\\\\\/]/i', $path)) {
                $path = base_path($path);
            }
            config(['database.connections.install_run' => [
                'driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true,
            ]]);
        } else {
            config(['database.connections.install_run' => [
                'driver' => 'mysql',
                'host' => $db['host'], 'port' => $db['port'],
                'database' => $db['database'], 'username' => $db['username'], 'password' => $db['password'],
                'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '',
            ]]);
        }
        DB::purge('install_run');

        return 'install_run';
    }
}
