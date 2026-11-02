<?php

namespace App\Support;

use Nwidart\Modules\Facades\Module;

/**
 * 核心层引用插件的门禁（守卫）。
 *
 * 插件中心的「删除插件」会真的删掉 Modules/{Name} 目录、移除 PSR-4 映射。
 * 核心代码若直接静态调用插件类（Foo::bar()），类不存在时 PHP 抛
 * Error: Class "..." not found —— 后台首页/列表页直接 500。
 *
 * 两种失效要分开看：
 * - 插件被删（目录没了）→ class_exists 为 false
 * - 插件被停用（目录还在，Provider 不加载）→ 类存在但 Module::isEnabled 为 false
 *
 * 所以判定要同时看「类在不在」和「模块启用没」，且 isEnabled 在模块被删时
 * 会抛 ModuleNotFoundException（FileRepository::findOrFail），必须 try/catch。
 *
 * 用法（短路是重点——类不存在时绝不能真正执行静态调用）：
 *
 *     PluginGuard::available(RecordTranslation::class, 'AiContentWriter')
 *         && RecordTranslation::available('product')
 */
class PluginGuard
{
    /**
     * 插件是否可用：类存在，且（指定了模块名时）模块处于启用状态。
     *
     * 任何异常都按「不可用」处理——这个方法用在渲染路径上，
     * 抛异常等于把后台打挂，比降级严重得多。
     *
     * class_exists 也要包 try/catch：插件目录被删后若 autoload 未刷新
     * （composer dump-autoload 失败的场景），Composer 的 stale classmap
     * 仍持有已删类的条目，class_exists 触发 include 缺失文件 → warning，
     * 被 Laravel 的错误处理器转成 ErrorException 抛出（实测确认）。
     */
    public static function available(string $class, ?string $module = null): bool
    {
        try {
            if (! class_exists($class)) {
                return false;
            }
        } catch (\Throwable) {
            // stale classmap 指向已删除的文件：include 失败抛 ErrorException
            return false;
        }

        if ($module === null) {
            return true;
        }

        try {
            return Module::isEnabled($module);
        } catch (\Throwable) {
            // 模块目录已被删除：isEnabled → findOrFail 抛 ModuleNotFoundException
            return false;
        }
    }
}
