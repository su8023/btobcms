<?php

namespace App\Http\Controllers;

use App\Models\User;
use Artisan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Web 安装向导（宝塔/任意主机一键安装）：
 *   GET  /install        环境自检（PHP 版本/扩展/目录权限）
 *   POST /install        提交数据库+管理员配置 → 迁移 → 建管理员 → 写 .env → 加安装锁
 *   GET  /install/done   完成页
 *
 * 防接管（三重，任一命中即视为已安装、入口 404）：
 *   1. storage/app/installed.lock 存在；
 *   2. 根目录 .env 存在且已配置非空 APP_KEY（真实部署必然有；
 *      即使 storage/ 未持久化导致锁文件丢失，此守卫依然关闭向导）；
 *   3. 安装过程中先落锁再改写 .env，中途失败会移除锁允许重试，
 *      因此不存在「.env 已被改写但向导仍开放」的中间态。
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
            // 可选：站点在 CDN/反代后面时的可信代理段（Cloudflare 等）。
            // 允许 IPv4/IPv6/CIDR/逗号分隔列表/`*`（空格允许，写入前归一化剥除）；
            // 其余字符一律拒绝，防止把任意内容带进 .env（该值稍后会被 config:cache 烘进缓存）。
            'trusted_proxies' => ['nullable', 'string', 'max:500', 'regex:/^[0-9A-Fa-f:.\/, \t*]+$/'],
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

        // 2. 先落锁：此刻起向导对匿名/并发请求关闭。
        //    锁在失败路径会被移除（允许重试），因此不会出现
        //    「.env 已被改写、锁却没写上」这种可被反复利用的中间态。
        if (! $this->writeLock('in_progress')) {
            return back()->withInput()->withErrors(['db_database' => '无法写入安装锁（storage/app 不可写），请修正目录权限后重试']);
        }

        // 3. 建独立运行时连接执行安装（不覆盖默认连接，避免影响当前会话/队列）
        $conn = $this->makeRuntimeConnection($db);

        try {
            // 4. 迁移 + （可选）演示数据 + 管理员
            //    全部走 install_run 运行时连接，不依赖 .env —— 这是「.env 最后落盘」的前提
            Artisan::call('migrate', ['--force' => true, '--database' => $conn]);

            if (! empty($data['seed_demo'])) {
                Artisan::call('db:seed', ['--force' => true, '--database' => $conn, '--class' => 'DemoContentSeeder']);
            }

            User::on($conn)->firstOrCreate(
                ['email' => $data['admin_email']],
                [
                    'name' => $data['admin_name'],
                    'password' => $data['admin_password'],
                    'email_verified_at' => now(),
                    'role' => 'admin',
                ],
            );

            // 5. 上面全部成功，才把新配置落进 .env（原子替换，中断不会截断原文件）
            $this->writeEnvironment($data, $db);
        } catch (Throwable $e) {
            // 失败：移除锁，允许运维重试（.env 尚未被改写，站点状态未被污染）
            $this->removeLock();

            return back()->withInput()->withErrors(['db_database' => '安装失败：'.$e->getMessage()]);
        }

        try {
            DB::purge('install_run');
        } catch (Throwable) {
        }

        // 发布 Livewire 静态资源：避免生产模式 JS 走 404 状态路由（部分浏览器/代理拒绝执行导致登录无响应）
        try {
            Artisan::call('vendor:publish', ['--tag' => 'livewire:assets', '--force' => true]);
        } catch (Throwable) {
        }

        if (! is_link(public_path('storage'))) {
            try {
                Artisan::call('storage:link');
            } catch (Throwable) {
                // 部分 Windows/受限主机软链失败，提示手工处理即可
            }
        }

        // 收尾自动生成性能缓存（config/event/view/route），装完零命令：
        // 必须走子进程——config:cache 若在当前请求进程内执行，会把驻留配置
        // （安装前 .env 的旧值）烘进缓存；子进程重新 boot 才能读到刚落盘的新 .env。
        // 失败不阻断安装（缓存是可选优化，站点无缓存也能跑），状态写进锁文件供完成页展示。
        $optimize = $this->runPostInstallOptimize();

        // 6. 标记安装完成（锁已存在，这里只更新状态字段）
        $this->writeLock('done', ['optimize' => $optimize]);

        return redirect()->to('/install/done');
    }

    public function done()
    {
        if (! $this->installed()) {
            abort(404);
        }

        $lock = [];

        try {
            $lock = (array) json_decode((string) File::get(storage_path(self::LOCK)), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
        }

        return view('install.wizard', ['step' => 'done', 'optimize' => $lock['optimize'] ?? null]);
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

    /**
     * 是否已安装：三重守卫，任一命中即关闭安装向导。
     *
     * 除锁文件外，还看根目录 .env 是否已配置非空 APP_KEY ——
     * 真实部署必然有 .env；即使 storage/ 未持久化导致锁文件丢失，
     * 这一条依然能挡住匿名重装（否则会被劫持 .env 并建出攻击者的管理员）。
     */
    private function installed(): bool
    {
        if (File::exists(storage_path(self::LOCK))) {
            return true;
        }

        return $this->environmentConfigured();
    }

    /**
     * .env 是否已配置好可运行的运行环境（有非空 APP_KEY 即视为已配置）。
     */
    private function environmentConfigured(): bool
    {
        $envFile = base_path('.env');

        if (! File::exists($envFile)) {
            return false;
        }

        $contents = (string) @file_get_contents($envFile);

        // 注意：这里只能用 [ \t] 而不是 \s —— \s 会连换行一起吃掉，
        // 于是 "APP_KEY=\nAPP_DEBUG=true" 会匹配到下一行的内容，
        // 把空 APP_KEY 误判成"已配置"、把安装向导错关掉。
        if (preg_match('/^[ \t]*APP_KEY[ \t]*=[ \t]*(\S.*)$/m', $contents, $m) !== 1) {
            return false;
        }

        $value = trim($m[1], " \t\"'");

        return $value !== '' && $value !== 'base64:';
    }

    /**
     * 写安装锁（安装开始即落锁，失败路径由 removeLock() 回滚）。
     * $extra：附加状态（如缓存生成结果），供完成页展示。
     */
    private function writeLock(string $status, array $extra = []): bool
    {
        $ok = File::put(storage_path(self::LOCK), json_encode(array_merge([
            'status' => $status,
            'installed_at' => $status === 'done' ? now()->toIso8601String() : null,
            'app_url' => config('app.url'),
        ], $extra), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $ok !== false;
    }

    /**
     * 移除安装锁：仅供失败回滚，允许运维重新走安装流程。
     */
    private function removeLock(): void
    {
        try {
            File::delete(storage_path(self::LOCK));
        } catch (Throwable) {
        }
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

        // APP_URL 会按当前访问域名固化进 .env（队列侧的收录推送/审计/GSC 都用它）：
        // 用 IP 或临时域名装完，换正式域名后这些功能全部指向错误地址
        $host = (string) request()->getHost();
        $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $checks[] = [
            'label' => '通过正式域名访问（当前：'.$host.'）',
            'pass' => ! $isIp && $host !== '' && $host !== 'localhost' && $host !== '127.0.0.1',
            'hint' => $isIp
                ? '⚠ 当前用 IP 访问，APP_URL 将固化为 IP！请先在宝塔绑定正式域名并通过域名访问后再安装'
                : '先配好 DNS 与站点域名，再用最终域名安装',
        ];

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

        $appName = $this->envValue($data['app_name']);

        // SQLite 必须写绝对路径：不同请求/进程的 CWD 不一致时相对路径直接连不上
        $dbDatabase = $db['database'];
        if ($db['connection'] === 'sqlite'
            && $dbDatabase !== ':memory:'
            && ! str_starts_with($dbDatabase, '/')
            && ! preg_match('/^[A-Z]:[\\\\\/]/i', $dbDatabase)) {
            $dbDatabase = str_replace('\\', '/', base_path($dbDatabase));
        }

        // 键不带等号（与下方行内正则捕获组一致），避免重复键：dotenv 不可变模式下首值生效
        // 密码/用户名等一律经 envValue 引号化：宝塔生成的密码常含 # 空格 $ 等字符，
        // 裸写会被 dotenv 当注释截断或解析失败，导致安装后数据库连不上
        $replacements = [
            'APP_NAME' => 'APP_NAME='.$appName,
            'APP_ENV' => 'APP_ENV=production',
            'APP_KEY' => 'APP_KEY=base64:'.base64_encode(random_bytes(32)),
            'APP_DEBUG' => 'APP_DEBUG=false',
            'APP_URL' => 'APP_URL='.$appUrl,
            'APP_TIMEZONE' => 'APP_TIMEZONE=Asia/Shanghai',
            'DB_CONNECTION' => 'DB_CONNECTION='.$db['connection'],
            'DB_HOST' => 'DB_HOST='.$this->envValue($db['host']),
            'DB_PORT' => 'DB_PORT='.$db['port'],
            'DB_DATABASE' => 'DB_DATABASE='.$this->envValue($dbDatabase),
            'DB_USERNAME' => 'DB_USERNAME='.$this->envValue($db['username']),
            'DB_PASSWORD' => 'DB_PASSWORD='.$this->envValue($db['password']),
            // 生产性能基线：file 驱动在高并发下每请求多次磁盘 IO，
            // database 驱动随 DB 走（迁移自带 sessions/cache 表），宝塔装完即最优默认
            'SESSION_DRIVER' => 'SESSION_DRIVER=database',
            'CACHE_STORE' => 'CACHE_STORE=database',
        ];

        // 可信代理（CDN/反代场景，可选）：只写非空值。
        // 逗号分隔列表去空格归一化——Laravel TrustProxies 按逗号 explode 后逐段 trim，
        // 但裸写进 .env 的值不带空格更干净，也避免触发 envValue 的引号化。
        $proxies = implode(',', array_filter(array_map(
            'trim',
            explode(',', trim((string) ($data['trusted_proxies'] ?? '')))
        ), static fn (string $segment): bool => $segment !== ''));

        if ($proxies !== '') {
            $replacements['TRUSTED_PROXIES'] = 'TRUSTED_PROXIES='.$this->envValue($proxies);
        }

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

        // 原子落盘：先写临时文件再 rename，避免进程中断把原 .env 截断成半截
        // （半截 .env 会让站点既起不来、也让安装向导处于「已配置」的错误状态）
        $target = base_path('.env');
        $tmp = $target.'.installing';

        if (File::put($tmp, implode("\n", $lines)."\n") === false) {
            throw new RuntimeException('无法写入 .env 临时文件：'.$tmp);
        }

        // Windows 上 rename 不能覆盖已存在文件，先移除旧文件再改名
        if (File::exists($target)) {
            File::delete($target);
        }

        if (! File::move($tmp, $target)) {
            @File::delete($tmp);

            throw new RuntimeException('无法写入 .env：'.$target);
        }
    }

    /**
     * dotenv 值安全序列化：纯安全字符裸写，其余双引号包裹并转义。
     * 覆盖密码含 # 空格 $ 引号 反斜杠等宝塔随机密码常见字符。
     * 逗号属于安全字符（TRUSTED_PROXIES 的 CIDR 列表可裸写）。
     */
    private function envValue(?string $value): string
    {
        $value = (string) $value;
        if ($value === '') {
            return '';
        }
        if (preg_match('/^[A-Za-z0-9._\-\/:+,]+$/', $value)) {
            return $value;
        }

        return '"'.str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\$'], $value).'"';
    }

    /**
     * 安装收尾：子进程生成性能缓存。返回写进锁文件的状态串。
     *
     * 为什么必须子进程：config:cache 会把「启动时加载的配置」烘进
     * bootstrap/cache/config.php。安装请求进程加载的是旧 .env（安装前的空/模板值），
     * 在进程内执行 Artisan::call('config:cache') 会把旧值永久固化，站点反而坏掉；
     * 子进程重新 boot 应用，读到的才是刚落盘的新 .env（与 SelfUpdater 同一套机制）。
     *
     * 失败不阻断安装：缓存只是可选优化（无缓存站点照常运行，仅少一些加载提速）。
     */
    private function runPostInstallOptimize(): string
    {
        // 测试环境不真跑子进程（避免污染仓库级缓存文件， Installer 行为由其他断言覆盖）
        if (app()->runningUnitTests()) {
            return 'skipped';
        }

        foreach (['config:cache', 'event:cache', 'view:cache'] as $command) {
            try {
                $this->artisanSubprocess($command);
            } catch (Throwable $e) {
                return 'failed: '.$command.' - '.$e->getMessage();
            }
        }

        // route:cache 存在闭包路由时会失败（Laravel 不支持缓存闭包路由），可接受
        try {
            $this->artisanSubprocess('route:cache');
        } catch (Throwable) {
        }

        return 'ok';
    }

    /**
     * 以独立 PHP 子进程执行 artisan 命令，返回输出；失败抛异常。
     *
     * 实现与 Modules/UpdateCenter/SelfUpdater::artisan() 保持同构：
     * PHP_BINDIR 优先（宝塔 FPM 下 PHP_BINARY 可能是 php-fpm，收到 artisan 参数
     * 会以 usage 码 64 退出），失败换下一个候选；Windows 加 bypass_shell。
     */
    private function artisanSubprocess(string $command): string
    {
        $cli = PHP_OS_FAMILY === 'Windows' ? PHP_BINDIR.'\\php.exe' : PHP_BINDIR.'/php';
        $candidates = array_values(array_unique([$cli, PHP_BINARY]));

        // 净化继承环境：剥掉所有 Laravel 相关键。
        // 若主机在安装前就有一份带空 APP_KEY 的 .env 残片，dotenv 部分适配器会把
        // 旧值 putenv 进本进程；子进程一旦继承这些键（immutable 模式优先读继承值），
        // config:cache 就会把安装前的旧配置烘进缓存。子进程必须只依赖新落盘的 .env。
        $env = [];
        foreach (getenv() as $key => $value) {
            if (! preg_match('/^(APP_|DB_|SESSION_|CACHE_|QUEUE_|MAIL_|TRUSTED_|REDIS_|FILESYSTEM_|AWS_|LARAVEL_)/', $key)) {
                $env[$key] = $value;
            }
        }

        $lastError = '';

        foreach ($candidates as $php) {
            $cmd = escapeshellarg($php).' '.escapeshellarg(base_path('artisan')).' '.$command;

            $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $process = @proc_open($cmd, $descriptors, $pipes, base_path(), $env, ['bypass_shell' => true]);

            if (! is_resource($process)) {
                $lastError = $php.' 无法启动（proc_open 失败）';

                continue;
            }

            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);

            // 64 = CLI usage 错误（php-fpm 收到未知参数的退出码）；artisan 不会用 64
            if ($exit === 64) {
                $lastError = $php.' 不是 CLI 解释器（疑似 php-fpm，exit 64）';

                continue;
            }

            if ($exit !== 0) {
                throw new RuntimeException(sprintf(
                    '%s 失败（exit %d）：%s',
                    $command,
                    $exit,
                    mb_substr(trim($stdout."\n".$stderr), 0, 400)
                ));
            }

            return trim($stdout."\n".$stderr);
        }

        throw new RuntimeException('无法启动 artisan '.$command.'：'.$lastError);
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
