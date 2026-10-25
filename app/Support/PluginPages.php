<?php

namespace App\Support;

use Nwidart\Modules\Facades\Module;

/**
 * 插件后台页面约定式发现：
 * 扫描所有已启用模块的 app/Filament/Pages/*.php，转换为 Filament 页面类注册。
 *
 * 约定（插件零核心改动自带后台页）：
 *   Modules/{Name}/app/Filament/Pages/{AnyPage}.php
 *   → 类名 Modules\{Name}\Filament\Pages\{AnyPage}
 *   → 仅模块启用时注册；停用即从导航消失。
 */
class PluginPages
{
    /**
     * @return list<class-string>
     */
    public static function discover(): array
    {
        $pages = [];

        foreach (Module::allEnabled() as $module) {
            $name = $module->getName();
            $dir = module_path($name, 'app/Filament/Pages');
            if (! is_dir($dir)) {
                continue;
            }

            foreach (glob($dir.'/*.php') ?: [] as $file) {
                $class = "Modules\\{$name}\\Filament\\Pages\\".basename($file, '.php');
                if (class_exists($class)) {
                    $pages[] = $class;
                }
            }
        }

        return $pages;
    }
}
