<?php

namespace App\Providers;

use App\Models\Post;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
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
                // 密钥必须跨请求稳定（会话 Cookie 用它加解密）：
                // 生成一次写入 storage/app/install.key，后续请求复用
                $keyFile = storage_path('app/install.key');
                if (is_file($keyFile)) {
                    config(['app.key' => (string) file_get_contents($keyFile)]);
                } else {
                    $generated = 'base64:'.base64_encode(random_bytes(32));
                    config(['app.key' => $generated]);
                    @file_put_contents($keyFile, $generated);
                }
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

        // 301 规则变更即时失效缓存：否则编辑/删除后旧跳转还要挂 1 小时
        \App\Models\Redirect::saved(function (\App\Models\Redirect $redirect) {
            Cache::forget('redirect:'.$redirect->from_path);
            // from_path 被改过的场合，旧路径的缓存一并失效
            if ($redirect->wasChanged('from_path')) {
                Cache::forget('redirect:'.$redirect->getOriginal('from_path'));
            }
        });
        \App\Models\Redirect::deleted(function (\App\Models\Redirect $redirect) {
            Cache::forget('redirect:'.$redirect->from_path);
        });

        // 主题视图覆盖的即时应用由 ApplyTheme 中间件按请求处理（见 ThemeManager::apply）
    }
}
