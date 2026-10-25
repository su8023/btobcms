<?php

namespace Modules\WhatsAppChat\Providers;

use App\Support\Slots;
use Nwidart\Modules\Support\ModuleServiceProvider;

class WhatsAppChatServiceProvider extends ModuleServiceProvider
{
    /**
     * The name of the module.
     */
    protected string $name = 'WhatsAppChat';

    /**
     * The lowercase version of the module name.
     */
    protected string $nameLower = 'whatsappchat';

    /**
     * Provider classes to register.
     *
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function boot(): void
    {
        parent::boot();

        // 配置键统一为 'whatsapp-chat'（与 plugin_setting 的 configPrefix 一致，
        // 使插件中心保存的数据库设置能正确回落到 .env/config）
        $this->mergeConfigFrom(__DIR__.'/../../config/config.php', 'whatsapp-chat');

        // 前台右下角浮动客服挂件：模块启用即向 floating 插槽注册视图。
        // 可见性由视图在渲染时自检（enabled/phone），原因：boot 阶段可能早于
        // 数据库迁移（测试/Octane），此时读取设置不可靠；渲染时数据库总是就绪。
        // 插件中心设置的 enabled=false 或号码为空 → 视图输出空，挂件消失。
        Slots::push('floating', 'whatsappchat::float-button');
    }
}
