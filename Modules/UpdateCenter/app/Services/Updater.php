<?php

namespace Modules\UpdateCenter\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * 更新编排器：维护模式 → 备份数据库 → 拉取并切换版本 → composer → 迁移 → 缓存 → 恢复访问。
 * 任一步失败自动回滚到起始版本并退出维护模式；全程写 storage/app/update-center.log。
 */
class Updater
{
    private string $logFile;

    public function __construct(
        private readonly GitService $git,
        private readonly string $composer = 'composer',
    ) {
        $this->logFile = (string) config('update-center.log_path');
    }

    public static function fromSettings(): self
    {
        return new self(
            GitService::fromSettings(),
            (string) (plugin_setting('update-center', 'composer_path', '') ?: config('update-center.composer_path')),
        );
    }

    /**
     * 更新前体检：不可通过的都会给出人话原因。
     *
     * @return list<string>
     */
    public function preflight(): array
    {
        $problems = [];
        if (! $this->git->isAvailable()) {
            $problems[] = '服务器找不到 git 命令（宝塔：软件商店安装 Git，或在插件设置里配绝对路径）';
        }
        if (! $this->git->isRepository()) {
            $problems[] = '站点目录不是 git 仓库（需按文档用 git clone 部署，或 git init 并关联远程）';
        } elseif (! $this->git->hasRemote()) {
            $problems[] = 'git 仓库未配置 origin 远程（git remote add origin git@github.com:你的仓库.git）';
        }
        if ($problems === [] && $this->git->isDirty() && ! config('update-center.allow_dirty')) {
            $problems[] = '服务器上有未提交的本地改动（先提交或还原：git status 查看；确要忽略请设 UPDATE_CENTER_ALLOW_DIRTY=true）';
        }

        return $problems;
    }

    /**
     * 执行更新到指定 tag，返回执行日志行数组；失败抛异常（已自动回滚）。
     *
     * @return list<string>
     *
     * @throws RuntimeException|Throwable
     */
    public function updateTo(string $targetTag): array
    {
        $log = [];
        $log[] = '=== 开始更新 → '.$targetTag.' （'.now()->format('Y-m-d H:i:s').'） ===';
        $this->writeLog($log);

        $startingTag = $this->git->currentTag() ?? $this->git->currentCommit() ?? 'HEAD';
        $log[] = "当前版本：{$startingTag}";

        try {
            // 1. 维护模式（访客看到 503 维护页，不是报错页面）
            $this->artisan('down', [], $log, '进入维护模式');

            // 2. 数据库备份（失败即中止——没有退路的更新不做）
            $backupFile = $this->backupDatabase($log);

            // 3. 拉取远程 tag
            $this->git->fetch();
            $log[] = '✓ 已拉取远程 tags';
            $this->writeLog($log);

            if (! $this->git->tagExists($targetTag)) {
                throw new RuntimeException("远程不存在 tag {$targetTag}");
            }

            // 3.5 备份运行时注册状态：composer.json 的 PSR-4（含 zip 上传插件的映射）
            //     与 modules_statuses.json（含管理员手工启停状态）都不在 tag 里，
            //     不备份的话 git checkout 会把它们还原——上传插件静默报废、启停状态丢失
            $this->preserveRuntimeState($log);

            // 4. 切换版本
            $this->git->checkout($targetTag);
            $this->restoreRuntimeState($log);
            $log[] = "✓ 已切换到 {$targetTag}";
            $this->writeLog($log);

            // 5. composer 依赖（vendor 不进 git，必须重装）
            $this->composerInstall($log);

            // 6. 数据库迁移
            $this->artisan('migrate', ['--force' => true], $log, '数据库迁移');

            // 7. 性能缓存重建
            $this->artisan('optimize', [], $log, '重建 config/route/view 缓存');

            // 8. 队列 worker 优雅重启（旧代码常驻内存，必须加载新版本）
            $this->artisan('queue:restart', [], $log, '重启队列 worker');

            // 9. 恢复访问
            $this->artisan('up', [], $log, '退出维护模式');
            $log[] = '=== 更新完成 ✓ 当前版本 '.$targetTag.' ===';
            $this->writeLog($log);

            return $log;
        } catch (Throwable $e) {
            $log[] = '✗ 更新失败：'.$e->getMessage();
            $log[] = '>>> 自动回滚到 '.$startingTag;
            try {
                $this->git->checkout($startingTag);
                $this->composerInstall($log, quiet: true);
                $this->artisan('optimize', [], $log, '回滚后重建缓存', quiet: true);
                $this->artisan('up', [], $log, '恢复访问', quiet: true);
                $log[] = '>>> 回滚完成，站点已恢复 '.$startingTag.' 版本';
            } catch (Throwable $rollbackError) {
                $log[] = '!!! 回滚也失败：'.$rollbackError->getMessage();
                $log[] = '!!! 请人工介入：ssh 到服务器执行 git checkout '.$startingTag.' 与 php artisan up';
                try {
                    $this->artisan('up', [], $log, '尽力恢复访问', quiet: true);
                } catch (Throwable) {
                }
            }
            $this->writeLog($log);
            throw $e;
        }
    }

    /**
     * 备份 checkout 前的运行时注册文件（进程内存持有，不落盘避免与 git 冲突）。
     */
    private array $runtimeStateBackup = [];

    private function preserveRuntimeState(array &$log): void
    {
        $this->runtimeStateBackup = [];

        foreach (['composer.json', 'modules_statuses.json'] as $file) {
            $path = base_path($file);
            $this->runtimeStateBackup[$file] = is_file($path)
                ? (string) file_get_contents($path)
                : null;
        }
    }

    /**
     * checkout 后恢复运行时注册：
     *   - composer.json：以 tag 版为底，补回 tag 中已消失、但模块目录仍存在的 PSR-4 条目（zip 上传插件）；
     *   - modules_statuses.json：以 tag 版为底，覆盖恢复备份中模块目录仍存在的条目（保留启停意图 + 上传插件），
     *     目录已被新版本删除的模块条目自然剔除。
     */
    private function restoreRuntimeState(array &$log): void
    {
        foreach (['composer.json', 'modules_statuses.json'] as $file) {
            $backup = $this->runtimeStateBackup[$file] ?? null;
            if ($backup === null) {
                continue;
            }

            $backupData = json_decode($backup, true);
            $path = base_path($file);
            $currentData = is_file($path)
                ? (json_decode((string) file_get_contents($path), true) ?? [])
                : [];

            if (! is_array($backupData)) {
                continue;
            }

            if ($file === 'composer.json') {
                $backupPsr4 = $backupData['autoload']['psr-4'] ?? [];
                $currentPsr4 = &$currentData['autoload']['psr-4'];
                $restored = 0;

                foreach ($backupPsr4 as $namespace => $dir) {
                    if (! isset($currentPsr4[$namespace])
                        && is_dir(base_path($dir))) {
                        $currentPsr4[$namespace] = $dir;
                        $restored++;
                    }
                }
                unset($currentPsr4);

                if ($restored > 0) {
                    file_put_contents($path, json_encode($currentData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
                    $log[] = "✓ 已恢复 {$restored} 条 PSR-4 映射（含 zip 上传插件）";
                }
            } else {
                $restored = 0;

                foreach ($backupData as $moduleName => $enabled) {
                    // 模块目录在 checkout 后仍存在才恢复（新版本移除的模块不再登记）
                    if (is_dir(base_path("Modules/{$moduleName}"))
                        && (! array_key_exists($moduleName, $currentData) || $currentData[$moduleName] !== $enabled)) {
                        $currentData[$moduleName] = $enabled;
                        $restored++;
                    }
                }

                if ($restored > 0) {
                    file_put_contents($path, json_encode($currentData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
                    $log[] = "✓ 已恢复 {$restored} 条插件启用状态（上传插件 + 手工启停）";
                }
            }
        }
    }

    private function artisan(string $command, array $parameters, array &$log, string $label, bool $quiet = false): void
    {
        $exitCode = Artisan::call($command, $parameters);
        $out = trim(Artisan::output());
        if ($exitCode !== 0) {
            // 缓存构建这类失败必须显式暴露：否则 optimize 失败被静默吞掉，
            // 站点带旧配置继续跑，问题滞后才爆发
            throw new RuntimeException("{$label} 失败（exit {$exitCode}）：".mb_substr($out, 0, 200));
        }
        $log[] = ($quiet ? '' : '✓ ').$label.($out !== '' && ! $quiet ? '：'.mb_substr($out, 0, 120) : '');
        $this->writeLog($log);
    }

    private function composerInstall(array &$log, bool $quiet = false): void
    {
        // 队列 worker 环境可能没有 HOME（composer 必需）——指向站点内可写目录
        $composerHome = storage_path('app/composer-home');
        if (! is_dir($composerHome)) {
            @mkdir($composerHome, 0775, true);
        }

        $result = Process::path(base_path())
            ->timeout(600)
            ->env(['HOME' => $composerHome, 'COMPOSER_HOME' => $composerHome])
            ->run([$this->composer, 'install', '--no-dev', '--optimize-autoloader', '--no-interaction']);
        if ($result->exitCode() !== 0) {
            throw new RuntimeException('composer install 失败：'.mb_substr($result->errorOutput() ?: $result->output(), 0, 300));
        }
        $log[] = ($quiet ? '' : '✓ ').'composer 依赖已同步';
        $this->writeLog($log);
    }

    /**
     * MySQL 用 mysqldump；SQLite 直接复制文件。备份文件保留最近 5 份。
     */
    private function backupDatabase(array &$log): string
    {
        $dir = (string) config('update-center.backup_dir');
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $file = $dir.'/db-'.now()->format('Ymd-His').'-backup.'.($this->dbDriver() === 'sqlite' ? 'sqlite' : 'sql.gz');

        if ($this->dbDriver() === 'sqlite') {
            $path = config('database.connections.sqlite.database');
            if (! copy($path, $file)) {
                throw new RuntimeException('SQLite 备份复制失败');
            }
        } else {
            $c = config('database.connections.mysql');
            $cmd = sprintf(
                'mysqldump -h%s -P%s -u%s %s %s | gzip > %s',
                escapeshellarg((string) $c['host']),
                escapeshellarg((string) $c['port']),
                escapeshellarg((string) $c['username']),
                $c['password'] !== '' && $c['password'] !== null ? '-p'.escapeshellarg((string) $c['password']) : '',
                escapeshellarg((string) $c['database']),
                escapeshellarg($file),
            );
            $result = Process::timeout(300)->run($cmd);
            if ($result->exitCode() !== 0) {
                throw new RuntimeException('mysqldump 失败：'.mb_substr($result->errorOutput(), 0, 200));
            }
        }

        $log[] = '✓ 数据库已备份 → '.basename($file);
        $this->writeLog($log);
        $this->pruneBackups($dir);

        return $file;
    }

    private function dbDriver(): string
    {
        return (string) config('database.default') === 'sqlite'
            ? 'sqlite'
            : (string) config('database.connections.'.config('database.default').'.driver', 'mysql');
    }

    private function pruneBackups(string $dir): void
    {
        $files = glob($dir.'/db-*') ?: [];
        sort($files);
        while (count($files) > 5) {
            @unlink(array_shift($files));
        }
    }

    private function writeLog(array $lines): void
    {
        @file_put_contents($this->logFile, implode("\n", $lines)."\n");
    }

    public static function readLogTail(int $lines = 40): string
    {
        $file = (string) config('update-center.log_path');
        if (! is_file($file)) {
            return '';
        }
        $all = file($file, FILE_IGNORE_NEW_LINES) ?: [];

        return implode("\n", array_slice($all, -$lines));
    }
}
