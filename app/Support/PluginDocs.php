<?php

namespace App\Support;

use Nwidart\Modules\Facades\Module;
use Throwable;

/**
 * 插件文档约定：模块提供 Modules\{Name}\Support\Docs 类（静态 content(): string，
 * 返回教程 HTML），插件中心卡片与插件设置页自动渲染。
 *
 * 消费方：
 *   - PluginCenter 卡片「使用教程」展开块（全部插件）
 *   - PluginSettingsPage 顶部「使用教程」折叠区（有设置页的插件）
 *
 * Docs 类不存在/渲染异常 → 返回 null，界面静默跳过。
 */
class PluginDocs
{
    /**
     * 全部插件名 → 教程 HTML 的懒加载缓存（单请求内）。
     *
     * @var array<string, ?string>
     */
    protected static array $resolved = [];

    public static function has(string $name): bool
    {
        return self::content($name) !== null;
    }

    public static function content(string $name): ?string
    {
        if (array_key_exists($name, self::$resolved)) {
            return self::$resolved[$name];
        }

        $class = 'Modules\\'.$name.'\\Support\\Docs';

        if (Module::find($name) === null || ! class_exists($class) || ! method_exists($class, 'content')) {
            return self::$resolved[$name] = null;
        }

        try {
            $html = trim((string) $class::content());

            return self::$resolved[$name] = $html !== '' ? $html : null;
        } catch (Throwable) {
            // 文档渲染失败不影响插件中心/设置页可用
            return self::$resolved[$name] = null;
        }
    }
}
