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
