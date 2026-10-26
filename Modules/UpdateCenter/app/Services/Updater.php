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
            $this->artisan(['down'], $log, '进入维护模式');

            // 2. 数据库备份（失败即中止——没有退路的更新不做）
            $backupFile = $this->backupDatabase($log);

            // 3. 拉取远程 tag
            $this->git->fetch();
            $log[] = '✓ 已拉取远程 tags';
            $this->writeLog($log);

            if (! $this->git->tagExists($targetTag)) {
                throw new RuntimeException("远程不存在 tag {$targetTag}");
            }

            // 4. 切换版本
            $this->git->checkout($targetTag);
            $log[] = "✓ 已切换到 {$targetTag}";
            $this->writeLog($log);

            // 5. composer 依赖（vendor 不进 git，必须重装）
            $this->composerInstall($log);

            // 6. 数据库迁移
            $this->artisan(['migrate', '--force'], $log, '数据库迁移');

            // 7. 性能缓存重建
            $this->artisan(['optimize'], $log, '重建 config/route/view 缓存');

            // 8. 队列 worker 优雅重启（旧代码常驻内存，必须加载新版本）
            $this->artisan(['queue:restart'], $log, '重启队列 worker');

            // 9. 恢复访问
            $this->artisan(['up'], $log, '退出维护模式');
            $log[] = '=== 更新完成 ✓ 当前版本 '.$targetTag.' ===';
            $this->writeLog($log);

            return $log;
        } catch (Throwable $e) {
            $log[] = '✗ 更新失败：'.$e->getMessage();
            $log[] = '>>> 自动回滚到 '.$startingTag;
            try {
                $this->git->checkout($startingTag);
                $this->composerInstall($log, quiet: true);
                $this->artisan(['optimize'], $log, '回滚后重建缓存', quiet: true);
                $this->artisan(['up'], $log, '恢复访问', quiet: true);
                $log[] = '>>> 回滚完成，站点已恢复 '.$startingTag.' 版本';
            } catch (Throwable $rollbackError) {
                $log[] = '!!! 回滚也失败：'.$rollbackError->getMessage();
                $log[] = '!!! 请人工介入：ssh 到服务器执行 git checkout '.$startingTag.' 与 php artisan up';
                try {
                    $this->artisan(['up'], $log, '尽力恢复访问', quiet: true);
                } catch (Throwable) {
                }
            }
            $this->writeLog($log);
            throw $e;
        }
    }

    private function artisan(array $command, array &$log, string $label, bool $quiet = false): void
    {
        Artisan::call($command[0], array_slice($command, 1) ?: []);
        $out = trim(Artisan::output());
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
