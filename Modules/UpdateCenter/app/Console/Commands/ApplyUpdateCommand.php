<?php

namespace Modules\UpdateCenter\Console\Commands;

use Illuminate\Console\Command;
use Modules\UpdateCenter\Services\SelfUpdater;
use Throwable;

/**
 * 执行站点更新（由后台点击后以脱离的 CLI 子进程派发）：
 *
 *   php artisan update:apply v1.4.5
 *
 * 为什么走 CLI 子进程：同步跑在 HTTP 请求里会被 nginx fastcgi 超时 /
 * FPM request_terminate_timeout 杀掉（实测），而子进程是独立 OS 进程，
 * 父请求死亡不影响它。SelfUpdater::run 每步落状态文件，可断点恢复。
 */
class ApplyUpdateCommand extends Command
{
    protected $signature = 'update:apply {tag : 目标版本号，如 v1.4.5}';

    protected $description = '执行站点更新到指定版本（自包含包下载/覆盖/迁移/缓存重建）';

    public function handle(SelfUpdater $updater): int
    {
        $tag = (string) $this->argument('tag');

        try {
            $log = $updater->run($tag);
        } catch (Throwable $e) {
            $this->error('更新失败：'.$e->getMessage());
            $this->line('详细状态见 storage/app/update-state.json，可在后台点「继续更新」从断点恢复。');

            return self::FAILURE;
        }

        $this->info(implode("\n", array_slice($log, -3)));

        return self::SUCCESS;
    }
}
