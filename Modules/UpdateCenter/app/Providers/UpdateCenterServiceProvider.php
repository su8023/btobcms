<?php

namespace Modules\UpdateCenter\Providers;

use Modules\UpdateCenter\Settings\PluginSettings;
use Nwidart\Modules\Support\ModuleServiceProvider;

class UpdateCenterServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'UpdateCenter';

    protected string $nameLower = 'updatecenter';

    protected array $commands = [
        \Modules\UpdateCenter\Console\Commands\ApplyUpdateCommand::class,
    ];

    public function boot(): void
    {
        parent::boot();

        $this->mergeConfigFrom(__DIR__.'/../../config/config.php', 'update-center');

        // 放开 Livewire 临时上传默认 12MB 限制：更新安装包约 23MB，
        // 否则「本地上传安装包」永远过不了校验（FileUpload 的 maxSize 管不到这一层）。
        config(['livewire.temporary_file_upload.rules' => 'file|max:204800']);

        // 容器绑定：SelfUpdater 的 repo/token 只能来自插件设置。
        // 若直接 app(SelfUpdater::class)，Laravel 容器会用标量参数的默认值
        // （空串）实例化 → 更新时误报「未配置 GitHub 仓库」。
        $this->app->bind(
            \Modules\UpdateCenter\Services\SelfUpdater::class,
            fn () => \Modules\UpdateCenter\Services\SelfUpdater::fromSettings(),
        );
    }

    /**
     * 插件设置面板字段（/admin/plugins/UpdateCenter/settings）。
     */
    public static function settingsSchema(): string
    {
        return PluginSettings::class;
    }
}
