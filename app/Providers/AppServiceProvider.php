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

        // Setting 的请求内记忆化同理：清空防跨实例/跨测试污染
        \App\Models\Setting::flushMemo();

        // 全新部署（无 .env、无安装锁）：给安装向导可运行的最低运行时配置，
        // 避免 session/cache 默认走 database、APP_KEY 缺失导致 /install 自身 500。
        //
        // 关键：逐键回退 —— 只有 .env 里【没有】显式配置该项时才用兜底值。
        // 旧实现无条件覆盖 database/session/cache/queue 四个驱动，
        // 于是 MySQL 部署（storage/ 未持久化、锁文件缺失）会静默改用本地 SQLite，
        // 表现为"数据不见了"；同时 queue 变 sync 让询盘邮件在 HTTP 请求里同步发送。
        //
        // 为什么从 config('app.install_fallbacks') 读而不是 env()：
        // 执行 php artisan config:cache 后 Laravel 不再加载 .env，env() 恒为 null，
        // 那时会把所有驱动都当成"未配置"而强制回退 —— 正是要避免的事故。
        // 配置已缓存 = 运维已显式配置过，此时一律不动。
        if (! $this->app->runningInConsole()
            && ! $this->app->environment('testing')
            && ! $this->app->configurationIsCached()
            && ! is_file(storage_path('app/installed.lock'))) {
            $fallbacks = [
                'session.driver' => 'file',
                'cache.default' => 'file',
                'queue.default' => 'sync',
                'database.default' => 'sqlite',
            ];

            foreach ($fallbacks as $configKey => $fallback) {
                $configured = config("app.install_fallbacks.{$configKey}");

                if ($configured === null || $configured === '') {
                    config([$configKey => $fallback]);
                }
            }

            // 临时 APP_KEY 兜底：仅在 APP_KEY 为空时启用（.env 存在但没填也兜）。
            //
            // 为什么不影响 `php artisan key:generate`：
            // 本块整体被 runningInConsole() 挡住 —— 命令行下不跑，
            // config('app.key') 保持 .env 里的原值（空），
            // key:generate 用它拼正则去 .env 里定位 APP_KEY 行，匹配得上、写得进去。
            // （key:generate 失败的另一个常见原因是宿主机环境里已经有 APP_KEY，
            //   dotenv 的 immutable 模式下不会被 .env 覆盖 —— 那是标准 Laravel 行为。）
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
        // URL 协议与代理信任解耦：
        // 默认不信任代理（未配 TRUSTED_PROXIES）时 X-Forwarded-Proto 不被采信，
        // 于是 CDN 后面的站点会把 canonical / hreflang / sitemap / og:url
        // 全部生成成 http:// —— 同一页面被搜索引擎当成 http 与 https 两个版本，
        // 是实打实的 SEO 硬伤。这里以服务端配置的 APP_URL 协议为准强制 URL 生成协议
        // （APP_URL 不受客户端影响，不存在被伪造降级成 http 的风险）。
        $appUrlScheme = parse_url((string) config('app.url'), PHP_URL_SCHEME);

        if (in_array($appUrlScheme, ['http', 'https'], true)) {
            \Illuminate\Support\Facades\URL::forceScheme($appUrlScheme);
        }

        // 后台「站点设置」的联系方式合并进 config：站点设置存 DB，而全站 8+ 处
        // （首页 CTA/行业页/服务页/邮件署名/llms.txt 等）直接读 config('site.contact.*')。
        // 启动时用 DB 值覆盖 env 默认值，后台改一处全站生效（含队列，重启后生效）。
        try {
            foreach (['email', 'phone', 'address'] as $contactField) {
                $value = \App\Models\Setting::get("site.contact.{$contactField}");
                if (filled($value)) {
                    config(["site.contact.{$contactField}" => is_string($value) ? $value : (string) $value]);
                }
            }
        } catch (\Throwable) {
            // 表未迁移（全新安装/测试引导阶段）时跳过：走 env 默认值
        }

        // 文章创建时自动记录作者（后台手动创建与 AI 生成都生效）
        Post::creating(function (Post $post) {
            $post->author_id ??= Auth::id();
        });

        // 301 规则变更即时失效缓存：否则编辑/删除后旧跳转还要挂 1 小时
        \App\Models\Redirect::saved(function (\App\Models\Redirect $redirect) {
            Cache::forget(\App\Models\Redirect::cacheKey($redirect->from_path));
            // from_path 被改过的场合，旧路径的缓存一并失效
            if ($redirect->wasChanged('from_path')) {
                Cache::forget(\App\Models\Redirect::cacheKey($redirect->getOriginal('from_path')));
            }
        });
        \App\Models\Redirect::deleted(function (\App\Models\Redirect $redirect) {
            Cache::forget(\App\Models\Redirect::cacheKey($redirect->from_path));
        });

        // 主题视图覆盖的即时应用由 ApplyTheme 中间件按请求处理（见 ThemeManager::apply）
    }
}
