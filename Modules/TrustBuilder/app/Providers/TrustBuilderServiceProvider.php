<?php

namespace Modules\TrustBuilder\Providers;

use App\Support\Slots;
use Nwidart\Modules\Support\ModuleServiceProvider;

class TrustBuilderServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'TrustBuilder';

    protected string $nameLower = 'trust-builder';

    public function boot(): void
    {
        parent::boot();

        // 配置键统一为 'trust-builder'（与 plugin_setting 的 configPrefix 一致）
        $this->mergeConfigFrom(__DIR__.'/../../config/config.php', 'trust-builder');

        // 基类已用 nameLower('trust-builder') 注册视图命名空间与语言包；
        // 本插件统一使用 'trustbuilder' 命名空间，再注册一次别名
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'trustbuilder');

        if (! config('trust-builder.enabled')) {
            return;
        }

        // 全站信任条：注入页脚上方插槽。
        // Provider 在 boot 阶段无条件注册本视图（boot 时数据库可能尚未就绪），
        // 可见性由视图在渲染时自检：无证书且无已发布案例不输出；案例页不重复渲染。
        Slots::push('footer_before', 'trustbuilder::trust-bar');

        // 插件样式（案例卡片/图集/信任条专属类）：注入 head 插槽，</head> 前加载
        Slots::push('head', 'trustbuilder::styles');

        // 信任条 5 分钟缓存的内容失效：案例/证书增删改即刻生效
        foreach ([\Modules\TrustBuilder\Models\TrustCase::class, \Modules\TrustBuilder\Models\TrustCertification::class] as $trustModel) {
            foreach (['saved', 'deleted'] as $trustEvent) {
                \Illuminate\Support\Facades\Event::listen("eloquent.{$trustEvent}: {$trustModel}", function (): void {
                    \Illuminate\Support\Facades\Cache::forget('trust-builder:trust-bar');
                });
            }
        }
    }
}
