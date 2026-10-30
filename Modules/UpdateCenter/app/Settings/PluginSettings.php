<?php

namespace Modules\UpdateCenter\Settings;

/**
 * 更新中心设置：GitHub 仓库与工具路径。
 */
class PluginSettings
{
    public static function configPrefix(): string
    {
        return 'update-center';
    }

    public static function fields(): array
    {
        return [
            ['key' => 'github_repo', 'label' => 'GitHub 仓库（owner/repo）', 'type' => 'text', 'required' => true,
                'placeholder' => 'your-name/btobcms', 'section' => 'integration',
                'hint' => '私有仓库需配 Token'],
            ['key' => 'github_token', 'label' => 'GitHub Token（私有仓库必填）', 'type' => 'password', 'section' => 'integration',
                'hint' => 'GitHub → Settings → Developer settings → Personal access tokens（只读 repos 权限即可）'],

        ];
    }
}
