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
