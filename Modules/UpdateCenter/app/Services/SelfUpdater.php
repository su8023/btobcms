<?php

namespace Modules\UpdateCenter\Services;

use FilesystemIterator;
use Illuminate\Support\Facades\Http;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * WordPress 式自更新器（v2，替代依赖 git/composer/队列的 v1 Updater）：
 *
 *   点击更新 → 同步在当前 HTTP 请求执行全流程，不依赖队列 worker / git / composer。
 *   发布包为自包含 zip（全部代码 + vendor），解压覆盖 + 保留式排除，进程内跑迁移与缓存重建。
 *
 * 与 WordPress 相同的安全哲学：
 *   - 每步落状态文件（storage/app/update-state.json），进程中断后后台出现
 *     「继续更新」按钮，从断点幂等恢复（覆盖重拷、migrate 天然幂等）；
 *   - 维护模式贯穿全程（访客看到 503），但后台面板与 Livewire 端点保持可达，
 *     否则中断后管理员将无法自助恢复；
 *   - .env / storage（用户数据）/ 数据库 / modules_statuses.json 永不被包覆盖。
 *
 * 关键实现约束：
 *   - artisan migrate/optimize 走**子进程**：子进程重新 boot 应用，加载的是磁盘上的
 *     新代码与新 composer PSR-4 映射（新模块的迁移路径与新类才能被发现/自动加载）。
 *   - 解压逐条目校验（拒绝 ../、绝对路径、符号链接）——Zip Slip 防护。
 *   - 覆盖前做完整性硬校验 + 备份原文件，失败可回滚。
 */
class SelfUpdater
{
    /**
     * 受保护的路径（相对包根）：整段目录或单文件，永不被包覆盖。
     * 语义为「路径段前缀匹配」，不是仅第一段。
     */
    protected const PRESERVE_DIRS = [
        'storage',     // 上传文件/磁铁文件/会话/视图缓存/日志
        '.git',        // 保留仓库便于回滚与排查
        '.well-known', // 证书验证目录
    ];

    protected const PRESERVE_FILES = [
        '.env', '.env.backup', '.env.production', '.env.local', '.user.ini',
        'modules_statuses.json',
    ];

    /** bootstrap 下只有 cache 子目录受保护（bootstrap/app.php 必须能被更新） */
    protected const PRESERVE_BOOTSTRAP_SUBDIR = 'cache';

    /** 包内必须存在的文件（缺一不可 → 拒绝覆盖，避免把站点覆盖到半残） */
    protected const REQUIRED_IN_PACKAGE = [
        'artisan',
        'composer.json',
        'bootstrap/app.php',
        'vendor/autoload.php',
        'public/index.php',
    ];

    /** 覆盖前的原文件备份目录（相对站点根） */
    protected const BACKUP_DIR = 'storage/app/backups';

    /** 按序执行的步骤（resume 从记录的步骤继续，均幂等） */
    protected const STEPS = ['download', 'maintenance', 'extract', 'swap', 'migrate', 'optimize', 'finish'];

    protected string $basePath;

    protected string $updateDir;

    protected string $logPath;

    /** 锁句柄（不 fclose：随进程退出自动释放） */
    protected $lockHandle = null;

    public function __construct(
        ?string $basePath = null,
        ?string $updateDir = null,
        ?string $logPath = null,
        protected string $repo = '',
        protected string $token = '',
        protected bool $skipArtisan = false, // 固定装置测试用：跳过进程内 artisan 步骤
    ) {
        $this->basePath = rtrim($basePath ?? base_path(), '/\\');
        $this->updateDir = rtrim($updateDir ?? storage_path('app/update'), '/\\');
        $this->logPath = $logPath ?: storage_path('app/update-center.log');
    }

    public static function fromSettings(): self
    {
        $repo = (string) plugin_setting('update-center', 'github_repo', '');
        if (! preg_match('#^[\w.-]+/[\w.-]+$#', $repo)) {
            $repo = ''; // 非法仓库名直接置空 → 下载步骤给出明确报错
        }

        return new self(
            repo: $repo,
            token: (string) plugin_setting('update-center', 'github_token', ''),
        );
    }

    // ----------------------------------------------------------
    // ---- 状态文件 ----
    // ----------------------------------------------------------

    /**
     * 状态文件路径：与更新下载目录同级（storage/app/update-state.json）。
     * 从注入的 updateDir 派生——生产与 storage_path 等价，测试隔离在 fixture 目录。
     */
    protected function statePath(): string
    {
        return dirname($this->updateDir).'/update-state.json';
    }

    protected function lockPath(): string
    {
        return dirname($this->updateDir).'/update.lock';
    }

    /**
     * 当前状态：null = 无进行中的更新。
     *
     * @return array{tag: string, step: string, status: string, log: list<string>, error: ?string}|null
     */
    public function state(): ?array
    {
        if (! is_file($this->statePath())) {
            return null;
        }

        $state = json_decode((string) file_get_contents($this->statePath()), true);

        return is_array($state) ? $state : null;
    }

    /**
     * 是否处于「可恢复」状态。
     * running 也要算：进程被强杀（SIGKILL / FPM 超时 / OOM）时没有 catch 分支，
     * 状态会永远停在 running——若此处只认 interrupted，恢复入口将永远不出现。
     */
    public function isResumable(): bool
    {
        $state = $this->state();
        if ($state === null) {
            return false;
        }

        $status = (string) ($state['status'] ?? '');
        $step = (string) ($state['step'] ?? '');

        if (in_array($step, ['done', 'finish'], true)) {
            return false;
        }

        return in_array($status, ['interrupted', 'running'], true);
    }

    public function saveState(string $tag, string $step, string $status, array $log, ?string $error = null): void
    {
        $path = $this->statePath();
        @mkdir(dirname($path), 0775, true);

        $payload = json_encode([
            'tag' => $tag, 'step' => $step, 'status' => $status,
            'log' => array_values($log), 'error' => $error,
            'updated_at' => now()->toIso8601String(),
            'pid' => getmypid(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // 原子写：先写临时文件再 rename，避免进程在写入瞬间被杀留下半截 JSON
        $tmp = $path.'.tmp.'.getmypid();
        if (@file_put_contents($tmp, $payload, LOCK_EX) !== false) {
            @rename($tmp, $path);
        } else {
            @unlink($tmp);
            @file_put_contents($path, $payload, LOCK_EX);
        }
    }

    /** 放弃中断的更新（留档为 abandoned，不再显示继续/放弃按钮） */
    public function abandon(): void
    {
        $state = $this->state();
        if ($state === null) {
            return;
        }
        $state['status'] = 'abandoned';
        file_put_contents($this->statePath(), json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    // ----------------------------------------------------------
    // ---- 互斥锁 ----
    // ----------------------------------------------------------

    /** 全流程互斥：返回 false 表示已有更新任务在跑 */
    public function acquireLock(): bool
    {
        @mkdir(dirname($this->lockPath()), 0775, true);
        $fh = @fopen($this->lockPath(), 'c+');
        if ($fh === false) {
            return false;
        }
        if (! @flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);

            return false;
        }
        ftruncate($fh, 0);
        fwrite($fh, (string) getmypid());
        fflush($fh);
        // 故意不 fclose：锁随进程退出自动释放
        $this->lockHandle = $fh;

        return true;
    }

    public function releaseLock(): void
    {
        if (is_resource($this->lockHandle)) {
            @flock($this->lockHandle, LOCK_UN);
            @fclose($this->lockHandle);
            $this->lockHandle = null;
        }
    }

    // ----------------------------------------------------------
    // ---- 执行 ----
    // ----------------------------------------------------------

    /**
     * 完整跑一次更新（同步、阻塞当前请求；调用方需 set_time_limit(0)）。
     *
     * @return list<string> 日志行
     */
    public function run(string $tag): array
    {
        $this->assertTag($tag);

        $log = ["=== 开始更新 → {$tag} （".now()->format('Y-m-d H:i:s').'） ==='];
        $this->saveState($tag, 'download', 'running', $log);

        return $this->execute($tag, self::STEPS, $log);
    }

    /**
     * 从本地上传的安装包更新（跳过 GitHub 下载环节）。
     * 给 GitHub 不可达/限流/私有仓库无 token 等场景一条自助通路。
     *
     * @param  string  $zipPath  本地 zip 绝对路径
     * @param  string  $tag      版本号（用于状态文件与备份目录）
     * @return list<string>
     */
    public function installFromLocalPackage(string $zipPath, string $tag): array
    {
        $this->assertTag($tag);

        if (! is_file($zipPath) || ! $this->zipLooksValid($zipPath)) {
            throw new RuntimeException('上传的安装包无效（不是可打开的 zip）');
        }

        @mkdir($this->updateDir, 0775, true);
        $target = $this->updateDir.'/'.$tag.'.zip';
        @unlink($target);
        if (! @copy($zipPath, $target)) {
            throw new RuntimeException('安装包保存失败（storage/app 不可写？）');
        }

        $log = ["=== 开始更新 → {$tag}（本地上传安装包） ===", '✓ 安装包已接收（'.round(filesize($target) / 1048576, 1).' MB）'];

        // 从解压步骤继续（下载已完成）
        return $this->execute($tag, array_slice(self::STEPS, 2), $log);
    }

    /**
     * 断点恢复：从状态文件记录的步骤继续（全部幂等）。
     *
     * @return list<string>
     */
    public function resume(): array
    {
        $state = $this->state();
        if ($state === null) {
            throw new RuntimeException('没有可恢复的更新状态');
        }

        $tag = (string) ($state['tag'] ?? '');
        $this->assertTag($tag);

        $log = $state['log'];
        $log[] = '=== 继续更新 '.$tag.'（从步骤 '.($state['step'] ?? '未知').' 恢复） ===';

        $from = array_search((string) ($state['step'] ?? ''), self::STEPS, true);
        if ($from === false) {
            throw new RuntimeException('状态文件中的步骤不可识别：'.($state['step'] ?? '空'));
        }

        // 恢复前确认包仍然就绪；不在则断点回退到 download
        $zipPath = $this->updateDir.'/'.$tag.'.zip';
        if (! is_file($zipPath) || ! $this->zipLooksValid($zipPath)) {
            $from = 0;
            $log[] = '⚠ 安装包缺失或损坏，恢复流程从下载步骤重跑';
        }

        return $this->execute($tag, array_slice(self::STEPS, (int) $from), $log);
    }

    /**
     * @param  list<string>  $steps
     * @param  list<string>  $log
     * @return list<string>
     */
    protected function execute(string $tag, array $steps, array $log): array
    {
        try {
            foreach ($steps as $step) {
                $log[] = '—— 步骤：'.$step;
                $this->saveState($tag, $step, 'running', $log);
                $this->{$step}($tag, $log);
            }
        } catch (Throwable $e) {
            $log[] = '✗ 更新中断：'.$e->getMessage();
            $this->saveState($tag, $this->state()['step'] ?? 'unknown', 'interrupted', $log, $e->getMessage());
            $this->writeLog($log);

            throw $e;
        }

        $log[] = '=== 更新完成 ✓ 当前版本 '.$tag.' ===';
        $this->saveState($tag, 'done', 'done', $log);
        $this->writeLog($log);

        return $log;
    }

    /** 版本号白名单：杜绝路径穿越与 API 注入 */
    protected function assertTag(string $tag): void
    {
        if (! preg_match('/^v?\d{1,4}(\.\d{1,4}){0,3}(-[0-9A-Za-z._-]{1,40})?$/', $tag)) {
            throw new RuntimeException('版本号格式非法：'.$tag);
        }
    }

    // ----------------------------------------------------------
    // ---- 步骤实现（全部幂等） ----
    // ----------------------------------------------------------

    /** ① 下载发布资产（btobcms-{tag}.zip）到 storage/app/update/ */
    protected function download(string $tag, array &$log): void
    {
        @mkdir($this->updateDir, 0775, true);
        $zipPath = $this->updateDir.'/'.$tag.'.zip';
        $partPath = $zipPath.'.part';

        // 只有「能打开且校验通过」的包才算已下载；残缺包一律重下（避免半截包死锁）
        if (is_file($zipPath)) {
            if ($this->zipLooksValid($zipPath)) {
                $log[] = '✓ 安装包已存在，跳过下载（'.round(filesize($zipPath) / 1048576, 1).' MB）';

                return;
            }
            @unlink($zipPath); // 残缺包，删掉重来
        }

        if ($this->repo === '') {
            throw new RuntimeException('未配置 GitHub 仓库（插件设置里填 owner/repo）');
        }

        $response = Http::timeout(20)
            ->withHeaders($this->githubHeaders())
            ->get("https://api.github.com/repos/{$this->repo}/releases/tags/{$tag}");
        if (! $response->successful()) {
            throw new RuntimeException('获取 Release 信息失败：HTTP '.$response->status());
        }

        $asset = null;
        foreach ((array) ($response->json('assets') ?? []) as $a) {
            if (($a['name'] ?? '') === 'btobcms-'.$tag.'.zip') {
                $asset = $a;
                break;
            }
        }
        if ($asset === null) {
            throw new RuntimeException("Release {$tag} 未附带安装包 btobcms-{$tag}.zip");
        }

        // 下载到 .part，绝不直接写最终文件
        @unlink($partPath);
        $resp = Http::timeout(300)
            ->withHeaders($this->githubHeaders())
            ->accept('application/octet-stream')
            ->sink($partPath)
            ->get($asset['url']);

        if (! $resp->successful() || ! is_file($partPath) || filesize($partPath) === 0) {
            @unlink($partPath);

            throw new RuntimeException('安装包下载失败：HTTP '.$resp->status());
        }

        // 校验 1：GitHub asset 的 digest（sha256:…）
        $expected = $this->expectedDigest($asset);
        $actual = hash_file('sha256', $partPath);
        if ($expected !== null && ! hash_equals($expected, (string) $actual)) {
            @unlink($partPath);

            throw new RuntimeException('安装包 SHA-256 校验失败，已丢弃下载文件');
        }

        // 校验 2：必须是可打开的 zip
        if (! $this->zipLooksValid($partPath)) {
            @unlink($partPath);

            throw new RuntimeException('安装包损坏（无法作为 zip 打开）');
        }

        rename($partPath, $zipPath); // 原子落地
        $log[] = '✓ 安装包已下载并校验通过（'.round(filesize($zipPath) / 1048576, 1).' MB，sha256='.substr((string) $actual, 0, 16).'…）';
    }

    /** ② 进入维护模式（访客看 503；后台保持可达以便自助恢复） */
    protected function maintenance(string $tag, array &$log): void
    {
        if ($this->skipArtisan) {
            $log[] = '✓ （测试跳过维护模式）';

            return;
        }
        if (app()->isDownForMaintenance()) {
            $log[] = '✓ 已处于维护模式';

            return;
        }
        $this->artisan('down', ['--retry' => 120]);
        $log[] = '✓ 进入维护模式（后台与更新中心仍可访问）';
    }

    /** ③ 解压到 staging 目录（逐条目校验，防 Zip Slip） */
    protected function extract(string $tag, array &$log): void
    {
        $zipPath = $this->updateDir.'/'.$tag.'.zip';
        $extractDir = $this->updateDir.'/extract';

        if (is_dir($extractDir)) {
            $this->rmrf($extractDir);
        }
        @mkdir($extractDir, 0775, true);

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('安装包无法打开（损坏？）');
        }
        $this->safeExtract($zip, $extractDir);
        $zip->close();

        // 包根归一化：若只有一层顶层目录且其中含 artisan，剥掉这层
        $root = $this->normalizePackageRoot($extractDir);
        if (! is_file($root.'/artisan')) {
            throw new RuntimeException('解压结果异常（缺 artisan），安装包可能不完整或根目录结构不符');
        }
        $log[] = '✓ 安装包解压完成（'.number_format($zip->numFiles ?: 0).' 个条目）';
    }

    /** ④ 保留式覆盖：先校验完整性 → 备份 → 原子覆盖（vendor 优先） */
    protected function swap(string $tag, array &$log): void
    {
        $extractDir = $this->updateDir.'/extract';
        $root = $this->normalizePackageRoot($extractDir);

        // ① 覆盖前：完整性硬校验（缺一不可 → 站点保持原样）
        foreach (self::REQUIRED_IN_PACKAGE as $required) {
            if (! is_file($root.'/'.$required)) {
                throw new RuntimeException("安装包不完整，缺少 {$required}，已放弃覆盖（站点未被修改）");
            }
        }

        // ② 收集待覆盖清单（跳过受保护路径）
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );
        foreach ($iterator as $file) {
            $path = (string) $file;
            if (is_link($path)) {
                continue; // 包内符号链接不复制
            }
            $relative = str_replace('\\', '/', substr($path, strlen(rtrim($root, '/\\').'/')));
            if ($this->isPreserved($relative)) {
                continue;
            }
            $files[] = [$path, $relative];
        }

        // ③ 排序：vendor 优先（依赖先就位），再其余
        usort($files, function (array $a, array $b): int {
            $rank = fn (string $r): int => str_starts_with($r, 'vendor/') ? 0 : 1;

            return $rank($a[1]) <=> $rank($b[1]);
        });

        // ④ 备份将被覆盖的原文件（供回滚）
        $backupDir = $this->basePath.'/'.self::BACKUP_DIR.'/'.$tag;
        $this->rmrf($backupDir);
        @mkdir($backupDir, 0775, true);

        $copied = 0;
        $failed = [];
        foreach ($files as [$path, $relative]) {
            $target = $this->basePath.'/'.$relative;

            // 目标必须仍在站点根之内（纵深防御）
            $this->assertInsideBase($target, $relative);

            if (is_file($target)) {
                $backup = $backupDir.'/'.$relative;
                @mkdir(dirname($backup), 0775, true);
                @copy($target, $backup);
            }

            @mkdir(dirname($target), 0755, true);

            // ⑤ 原子覆盖：先写临时文件，再落到目标路径。
            //    注意：Windows 的 rename() 不能覆盖已存在的目标（POSIX 可以），
            //    故先 unlink 旧文件再 rename —— 目标路径要么是旧文件、要么是完整新文件，
            //    不会出现「写了一半的截断文件」。
            $tmp = $target.'.upd.'.getmypid();
            $isCritical = in_array($relative, self::REQUIRED_IN_PACKAGE, true);

            $ok = @copy($path, $tmp);
            if ($ok) {
                @chmod($tmp, is_file($target) ? (fileperms($target) & 0777) : 0644);
                @unlink($target);
                $ok = @rename($tmp, $target);
            }
            if (! $ok) {
                @unlink($tmp);

                if ($isCritical) {
                    throw new RuntimeException('替换关键文件失败：'.$relative);
                }

                // 非关键文件失败（如被占用）：记录并继续，不中断整个更新
                $failed[] = $relative;

                continue;
            }
            $copied++;
        }

        // ⑥ 备份清单落盘（供回滚）
        @file_put_contents($backupDir.'/.manifest.json', json_encode([
            'tag' => $tag, 'base' => $this->basePath, 'files' => array_column($files, 1),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // ⑦ 合并模块启用状态（放在覆盖之后：新模块文件已就位）
        $this->mergeModuleStatuses($root);

        // ⑧ 清理全部编译缓存（不要硬编码白名单）
        foreach (glob($this->basePath.'/bootstrap/cache/*.php') ?: [] as $cached) {
            @unlink($cached);
        }

        if ($failed !== []) {
            $log[] = '⚠ '.count($failed).' 个文件未能替换（多因被占用，Linux 服务器少见）：'.implode('、', array_slice($failed, 0, 5));
        }
        $log[] = "✓ 已覆盖 {$copied} 个文件（原文件备份于 ".self::BACKUP_DIR."/{$tag}）";
    }

    /** ⑤ 进程内执行数据库迁移（子进程 → 加载新代码与新 PSR-4 映射） */
    protected function migrate(string $tag, array &$log): void
    {
        if ($this->skipArtisan) {
            $log[] = '✓ （测试跳过迁移）';

            return;
        }
        $output = $this->artisan('migrate', ['--force' => true]);
        $log[] = '✓ 数据库迁移完成'.($output !== '' ? '：'.mb_substr($output, 0, 200) : '');
    }

    /** ⑥ 重建配置/事件/视图缓存（子进程 → 新的 provider 列表） */
    protected function optimize(string $tag, array &$log): void
    {
        if ($this->skipArtisan) {
            $log[] = '✓ （测试跳过缓存重建）';

            return;
        }

        foreach (['config:cache', 'event:cache', 'view:cache'] as $cmd) {
            $this->artisan($cmd);
        }

        // route:cache 失败（如存在闭包路由）不应中断更新
        try {
            $this->artisan('route:cache');
            $log[] = '✓ 路由缓存已生成';
        } catch (Throwable $e) {
            $log[] = '⚠ 路由缓存生成失败（可能存在闭包路由），已跳过：'.mb_substr($e->getMessage(), 0, 200);
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        $log[] = '✓ 缓存重建完成（config/event/view）';
    }

    /** ⑦ 退出维护模式并清理解压产物 */
    protected function finish(string $tag, array &$log): void
    {
        if (! $this->skipArtisan) {
            $this->artisan('up');
        }
        if (is_dir($this->updateDir.'/extract')) {
            $this->rmrf($this->updateDir.'/extract');
        }
        $log[] = '✓ 已退出维护模式，站点恢复访问';
    }

    // ----------------------------------------------------------
    // ---- 回滚 ----
    // ----------------------------------------------------------

    /**
     * 回滚到覆盖前的备份（用户点「恢复访问并放弃」时调用）。
     */
    public function rollback(): void
    {
        $state = $this->state();
        $tag = (string) ($state['tag'] ?? '');
        if ($tag === '') {
            return;
        }

        $backupDir = $this->basePath.'/'.self::BACKUP_DIR.'/'.$tag;
        $manifest = $backupDir.'/.manifest.json';
        if (! is_file($manifest)) {
            return; // 还没开始覆盖，无需回滚
        }

        $data = json_decode((string) file_get_contents($manifest), true);
        foreach ($data['files'] ?? [] as $relative) {
            $backup = $backupDir.'/'.$relative;
            $target = $this->basePath.'/'.$relative;
            if (is_file($backup)) {
                @copy($backup, $target);           // 还原旧文件
            } elseif (is_file($target)) {
                @unlink($target);                  // 包里新增的文件：删除
            }
        }

        foreach (glob($this->basePath.'/bootstrap/cache/*.php') ?: [] as $cached) {
            @unlink($cached);
        }
    }

    // ----------------------------------------------------------
    // ---- 安全辅助 ----
    // ----------------------------------------------------------

    /**
     * 路径是否受保护（任意层级段前缀匹配，不是仅第一段）。
     */
    protected function isPreserved(string $relative): bool
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');
        $parts = explode('/', $relative);
        $first = $parts[0] ?? '';

        foreach (self::PRESERVE_DIRS as $guard) {
            if ($first === $guard) {
                return true;
            }
        }

        if (in_array($first, self::PRESERVE_FILES, true)) {
            return true;
        }

        // .env.* 一律保护（.env.example 例外——它属于代码，应随更新）
        if (str_starts_with($first, '.env.') && $first !== '.env.example') {
            return true;
        }

        // bootstrap 只有 cache 子目录受保护（bootstrap/app.php 必须能被覆盖）
        if ($first === 'bootstrap') {
            return ($parts[1] ?? '') === self::PRESERVE_BOOTSTRAP_SUBDIR;
        }

        // public/storage 是指向用户上传目录的符号链接，绝不能被包替换
        if ($first === 'public' && ($parts[1] ?? '') === 'storage') {
            return true;
        }

        // SQLite/其他数据库文件绝不能被包覆盖（否则全站数据被清空）
        if ($first === 'database' && preg_match('/\.(sqlite|sqlite3|db)(-\w+)?$/i', $parts[1] ?? '')) {
            return true;
        }

        return false;
    }

    /**
     * 目标路径必须仍在站点根之内（纵深防御）。
     * 目标目录可能尚不存在（新建目录），此时向上找到最近存在的祖先校验。
     */
    protected function assertInsideBase(string $target, string $relative): void
    {
        $baseReal = realpath($this->basePath);
        if ($baseReal === false) {
            throw new RuntimeException('站点根目录不可解析：'.$this->basePath);
        }
        $baseReal = rtrim($baseReal, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        // 目标所在目录（可能还不存在）→ 向上找最近存在的祖先
        $probe = dirname($target);
        $real = null;
        while ($probe !== '' && $probe !== '/' && $probe !== '.' && $probe !== dirname($probe)) {
            $real = realpath($probe);
            if ($real !== false) {
                break;
            }
            $probe = dirname($probe);
        }
        $real ??= realpath($this->basePath);

        if ($real === false
            || ! str_starts_with(rtrim($real, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR, $baseReal)) {
            throw new RuntimeException('拒绝写入站点根目录之外的路径：'.$relative);
        }
    }

    /**
     * 逐条目校验并解压。拒绝：绝对路径、盘符、UNC、'..' 路径段、NUL、符号链接条目。
     */
    protected function safeExtract(ZipArchive $zip, string $destDir): void
    {
        $destReal = realpath($destDir);
        if ($destReal === false) {
            throw new RuntimeException('解压目录不可用');
        }
        $destReal = rtrim($destReal, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if ($name === '') {
                continue;
            }

            // 符号链接条目（Unix 属性 0xA000）直接拒绝
            $opsys = 0;
            $attr = 0;
            $zip->getExternalAttributesIndex($i, $opsys, $attr);
            if ($opsys === ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0xF000) === 0xA000) {
                throw new RuntimeException('安装包含符号链接条目，拒绝安装：'.$name);
            }

            $normalized = str_replace('\\', '/', $name);

            // 绝对路径 / 盘符 / UNC
            if (str_starts_with($normalized, '/')
                || preg_match('#^[A-Za-z]:/#', $normalized)
                || str_starts_with($normalized, '//')) {
                throw new RuntimeException('安装包含绝对路径条目，拒绝安装：'.$name);
            }

            // '..' 路径段
            foreach (explode('/', $normalized) as $seg) {
                if ($seg === '..') {
                    throw new RuntimeException('安装包含路径穿越条目，拒绝安装：'.$name);
                }
            }
            if (str_contains($normalized, "\0")) {
                throw new RuntimeException('安装包含非法字符条目：'.$name);
            }

            $target = $destDir.DIRECTORY_SEPARATOR.$normalized;

            // 目标必须仍在解压目录内
            $parentReal = realpath(dirname($target));
            if ($parentReal !== false
                && ! str_starts_with(rtrim($parentReal, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR, $destReal)) {
                throw new RuntimeException('安装条目越界，拒绝安装：'.$name);
            }

            if (str_ends_with($normalized, '/')) {
                @mkdir($target, 0755, true);
                continue;
            }

            @mkdir(dirname($target), 0755, true);

            // 不使用 extractTo：改为流式写入我们自己校验过的目标
            $stream = $zip->getStream($name);
            if ($stream === false) {
                throw new RuntimeException('读取压缩条目失败：'.$name);
            }
            $out = @fopen($target, 'wb');
            if ($out === false) {
                fclose($stream);

                throw new RuntimeException('写入解压目标失败：'.$target);
            }
            stream_copy_to_stream($stream, $out);
            fclose($out);
            fclose($stream);
        }
    }

    /**
     * 包根归一化：若 zip 只有一层顶层目录且其中含 artisan，剥掉这层。
     */
    protected function normalizePackageRoot(string $extractDir): string
    {
        $entries = array_values(array_filter(scandir($extractDir) ?: [], fn ($e) => ! in_array($e, ['.', '..'], true)));
        if (count($entries) === 1 && is_dir($extractDir.'/'.$entries[0]) && is_file($extractDir.'/'.$entries[0].'/artisan')) {
            return $extractDir.'/'.$entries[0];
        }

        return $extractDir;
    }

    /**
     * 合并模块启用状态：新包条目为缺省（新插件默认启用），既有模块保留现有启停状态。
     */
    protected function mergeModuleStatuses(string $root): void
    {
        $statusesPath = $this->basePath.'/modules_statuses.json';
        $current = is_file($statusesPath)
            ? (json_decode((string) file_get_contents($statusesPath), true) ?? [])
            : [];
        $package = is_file($root.'/modules_statuses.json')
            ? (json_decode((string) file_get_contents($root.'/modules_statuses.json'), true) ?? [])
            : [];

        $merged = $current;
        foreach ($package as $module => $enabled) {
            if (! array_key_exists($module, $merged)) {
                $merged[$module] = $enabled;
            }
        }

        $tmp = $statusesPath.'.tmp';
        if (@file_put_contents($tmp, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n", LOCK_EX) === false) {
            throw new RuntimeException('写入 modules_statuses.json 失败');
        }
        // Windows 的 rename() 不能覆盖已存在的目标（POSIX 可以）——先删旧再 rename
        @unlink($statusesPath);
        if (! @rename($tmp, $statusesPath)) {
            @unlink($tmp);

            throw new RuntimeException('更新 modules_statuses.json 失败');
        }
    }

    /**
     * 以独立 PHP 子进程执行 artisan。
     * 关键：子进程会重新 boot 应用 → 加载磁盘上的【新】代码、新 composer PSR-4 映射、
     * 新模块的迁移路径；当前进程里驻留的旧类不会干扰。
     */
    protected function artisan(string $command, array $args = []): string
    {
        $parts = [escapeshellarg(PHP_BINARY), escapeshellarg($this->basePath.'/artisan'), $command];
        foreach ($args as $k => $v) {
            $parts[] = '--'.$k.'='.escapeshellarg((string) $v);
        }
        $cmd = implode(' ', $parts);

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open($cmd, $descriptors, $pipes, $this->basePath, null, ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new RuntimeException("无法启动 artisan {$command}（proc_open 不可用？）");
        }

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        if ($exit !== 0) {
            throw new RuntimeException(sprintf(
                'artisan %s 失败（exit %d）：%s',
                $command,
                $exit,
                mb_substr(trim($stdout."\n".$stderr), 0, 800)
            ));
        }

        return trim($stdout."\n".$stderr);
    }

    protected function zipLooksValid(string $path): bool
    {
        if (! is_file($path) || filesize($path) < 1024) {
            return false;
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            return false;
        }
        $ok = $zip->numFiles > 0;
        $zip->close();

        return $ok;
    }

    protected function expectedDigest(array $asset): ?string
    {
        $digest = $asset['digest'] ?? null;

        return (is_string($digest) && str_starts_with($digest, 'sha256:'))
            ? substr($digest, 7)
            : null;
    }

    /** 纯 PHP 递归删除（不依赖 exec/rm，Windows 与禁用 exec 的环境同样可用） */
    protected function rmrf(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $f) {
            if ($f->isDir() && ! $f->isLink()) {
                @rmdir($f->getPathname());
            } else {
                @unlink($f->getPathname());
            }
        }

        if (! @rmdir($dir)) {
            throw new RuntimeException('清理目录失败：'.$dir);
        }
    }

    protected function githubHeaders(): array
    {
        return array_filter([
            'Authorization' => $this->token !== '' ? 'Bearer '.$this->token : null,
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'btobcms-self-updater',
        ]);
    }

    protected function writeLog(array $lines): void
    {
        @mkdir(dirname($this->logPath), 0775, true);
        @file_put_contents($this->logPath, implode("\n", $lines)."\n", FILE_APPEND | LOCK_EX);
    }

    public static function readLogTail(int $lines = 50): string
    {
        $file = (string) config('update-center.log_path');
        if (! is_file($file)) {
            return '';
        }

        $all = @file($file, FILE_IGNORE_NEW_LINES) ?: [];

        return implode("\n", array_slice($all, -$lines));
    }

    /** 更新前置检查：无 git/composer 依赖，只查自身能力 */
    public static function preflight(): array
    {
        $problems = [];

        if (! class_exists('ZipArchive')) {
            $problems[] = 'PHP 缺少 zip 扩展（宝塔：PHP 设置 → 安装扩展 zip）';
        }

        foreach ([
            storage_path('app') => 'storage/app',
            base_path() => '站点根目录',
            database_path() => 'database/（数据库目录）',
            base_path('bootstrap/cache') => 'bootstrap/cache',
        ] as $dir => $label) {
            if (! is_dir($dir) || ! is_writable($dir)) {
                $problems[] = "{$label} 不可写";
            }
        }

        // 磁盘余量：包 + 解压 + 备份 ≈ 包体积 × 3
        $free = @disk_free_space(storage_path('app'));
        if ($free !== false && $free < 512 * 1024 * 1024) {
            $problems[] = 'storage 所在磁盘剩余空间不足 512 MB（更新需要约包体积的 3 倍）';
        }

        return $problems;
    }
}
