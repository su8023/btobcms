<?php

namespace App\Support;

/**
 * 前台插槽注册表：插件在 Provider boot 时向命名插槽注册视图片段，
 * 布局在对应位置渲染。模块停用后 Provider 不再执行，注入自动消失。
 *
 * 预定义插槽：
 * - head     </head> 前（meta/样式）
 * - floating 页面右下角浮动层（客服挂件等）
 */
class Slots
{
    /** @var array<string, list<string>> */
    protected static array $slots = [];

    /**
     * 清空全部插槽注册。
     * 必须在每个应用实例的 register 阶段调用（早于所有 Provider boot）：
     * 测试进程会复用同一个 PHP 进程创建多个应用实例，Octane 等长驻进程同理——
     * 不清空的话，上一个应用注册的插槽会泄漏到下一个请求。
     */
    public static function flush(): void
    {
        static::$slots = [];
    }

    public static function push(string $slot, string $view): void
    {
        static::$slots[$slot][] = $view;
    }

    /** @return list<string>
     */
    public static function get(string $slot): array
    {
        return static::$slots[$slot] ?? [];
    }
}
