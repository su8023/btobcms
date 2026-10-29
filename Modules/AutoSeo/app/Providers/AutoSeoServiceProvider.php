<?php

namespace Modules\AutoSeo\Providers;

use App\Events\PostPublished;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Modules\AutoSeo\Listeners\OptimizeAndSubmitPost;
use Modules\AutoSeo\Support\IndexNow;
use Nwidart\Modules\Support\ModuleServiceProvider;

class AutoSeoServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'AutoSeo';

    protected string $nameLower = 'autoseo';

    public function boot(): void
    {
        parent::boot();

        $this->mergeConfigFrom(__DIR__.'/../../config/config.php', 'autoseo');

        // 文章发布 → 全自动 SEO 闭环（队列异步执行，失败仅记日志）
        Event::listen(PostPublished::class, OptimizeAndSubmitPost::class);

        // IndexNow 所有权验证文件：https://站点/{key}.txt（协议标准路径带 .txt 后缀；
        // 32 位 hex 约束使参数不可能吞掉 .txt）。保留裸 /{key} 兼容早期文档写法。
        Route::get('/{key}.txt', function (string $key) {
            abort_unless(hash_equals(IndexNow::key(), $key), 404);

            return response(IndexNow::key(), 200)
                ->header('Content-Type', 'text/plain; charset=utf-8');
        })->where('key', '[a-f0-9]{32}')->name('autoseo.keyfile.txt');

        Route::get('/{key}', function (string $key) {
            abort_unless(hash_equals(IndexNow::key(), $key), 404);

            return response(IndexNow::key(), 200)
                ->header('Content-Type', 'text/plain; charset=utf-8');
        })->where('key', '[a-f0-9]{32}')->name('autoseo.keyfile');
    }
}
