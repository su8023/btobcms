<?php

namespace App\Providers;

use App\Models\Post;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // 插槽注册是静态状态：每个应用实例创建时清空，
        // 防止测试进程/Octane 中上一个实例的插件注入泄漏到当前请求
        \App\Support\Slots::flush();

        // 全新部署（无 .env、无安装锁）：给安装向导可运行的最低运行时配置，
        // 避免 session/cache 默认走 database、APP_KEY 缺失导致 /install 自身 500。
        if (! $this->app->runningInConsole()
            && ! $this->app->environment('testing')
            && ! is_file(storage_path('app/installed.lock'))) {
            config([
                'session.driver' => 'file',
                'cache.default' => 'file',
                'queue.default' => 'sync',
                'database.default' => 'sqlite',
            ]);
            if (blank(config('app.key'))) {
                config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
            }
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 文章创建时自动记录作者（后台手动创建与 AI 生成都生效）
        Post::creating(function (Post $post) {
            $post->author_id ??= Auth::id();
        });

        // 主题视图覆盖的即时应用由 ApplyTheme 中间件按请求处理（见 ThemeManager::apply）
    }
}
