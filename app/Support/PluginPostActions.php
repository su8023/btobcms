<?php

namespace App\Support;

use Filament\Actions\Action;
use Nwidart\Modules\Facades\Module;

/**
 * 插件文章编辑器动作约定式发现（与 PluginPages 同模式）：
 *
 *   Modules/{Name}/app/Support/PostActions.php
 *   → 类 Modules\{Name}\Support\PostActions 的静态方法 editorActions(string $page): array
 *   → $page 为 'create' 或 'edit'；返回 Filament\Action 列表，合并进文章编辑页头部。
 *
 * 仅启用模块参与；停用即动作消失。核心 Post 页面零插件依赖。
 */
class PluginPostActions
{
    /**
     * @param  'create'|'edit'  $page
     * @return list<Action>
     */
    public static function forPage(string $page): array
    {
        $actions = [];

        foreach (Module::allEnabled() as $module) {
            $class = 'Modules\\'.$module->getName().'\\Support\\PostActions';

            if (! class_exists($class) || ! method_exists($class, 'editorActions')) {
                continue;
            }

            foreach ((array) $class::editorActions($page) as $action) {
                if ($action instanceof Action) {
                    $actions[] = $action;
                }
            }
        }

        return $actions;
    }
}
