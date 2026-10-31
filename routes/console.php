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

// 队列消费：业务监听器（询盘邮件/飞书通知/AutoSeo TDK/ImageForge 转换）全部 ShouldQueue，
// 默认 database 队列且宝塔环境无常驻 worker——没有这条，通知类任务会永远躺库静默丢失。
// 每分钟借 schedule:run 清空队列（50 秒上限防跨分钟重叠），不需要常驻进程。
Schedule::command('queue:work --stop-when-empty --max-time=50')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();
