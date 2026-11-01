<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// 队列治理：database 队列下 failed_jobs 无限增长会拖慢运维与占库，
// 每周日清理 7 天前的失败任务（各插件的业务清理在自己模块调度里）
Schedule::command('queue:prune-failed --hours=168')->weekly()->sundays()->at('03:40');

// AI 响应留档治理：写作室/翻译的解析失败会留档原始响应到 storage/app/ai-articles/
// 供排障（复核 D-P2-2），无清理就会无限增长——每周清 30 天前的存档
Schedule::call(function (): void {
    $dir = storage_path('app/ai-articles');

    if (! is_dir($dir)) {
        return;
    }

    $pruned = 0;

    foreach (glob($dir.'/*.txt') ?: [] as $file) {
        if (filemtime($file) < now()->subDays(30)->getTimestamp()) {
            @unlink($file);
            $pruned++;
        }
    }

    if ($pruned > 0) {
        \Illuminate\Support\Facades\Log::info("[AiContentWriter] 清理 30 天前 AI 响应留档 {$pruned} 个。");
    }
})->name('ai-writer.prune-dumps')->weekly()->sundays()->at('03:50');

// 队列消费：业务监听器（询盘邮件/飞书通知/AutoSeo TDK/ImageForge 转换）全部 ShouldQueue，
// 默认 database 队列且宝塔环境无常驻 worker——没有这条，通知类任务会永远躺库静默丢失。
// 每分钟借 schedule:run 清空队列（50 秒上限防跨分钟重叠），不需要常驻进程。
// AI 翻译任务在独立的 ai-translate 队列（单任务可达十几分钟），排在 default
// 之后消化——邮件等快任务不被长任务队头阻塞（复核 P2-5）。
$defaultQueue = (string) config('queue.connections.database.queue', 'default');

Schedule::command("queue:work --stop-when-empty --max-time=50 --queue={$defaultQueue},ai-translate")
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();
