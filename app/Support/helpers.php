<?php

/**
 * 全站多语言路由辅助函数。
 */

if (! function_exists('site_setting')) {
    /**
     * 读取全站设置（Setting 表，全局），未设置时回退到传入默认值。
     */
    function site_setting(string $key, mixed $default = null): mixed
    {
        return \App\Models\Setting::get($key, $default);
    }
}

if (! function_exists('plugin_setting')) {
    /**
     * 读取插件设置：后台插件中心保存的数据库值优先，其次插件 config（.env），最后默认值。
     *
     * @param  string  $configPrefix  插件配置前缀，如 feishu-notifier（同时用于推导模块名）
     * @param  string  $field  设置字段名（与插件 settings schema 的 key 一致）
     */
    function plugin_setting(string $configPrefix, string $field, mixed $default = null): mixed
    {
        // 连续大写的模块名（如 WhatsAppChat）无法由 ucwords 推导，需显式映射；
        // 新插件若含连续大写，请在此补充。
        $moduleMap = [
            'whatsapp-chat' => 'WhatsAppChat',
        ];

        $module = $moduleMap[$configPrefix] ?? str_replace('-', '', ucwords($configPrefix, '-'));

        try {
            $values = \App\Models\Setting::get("plugin.{$module}");
        } catch (\Throwable) {
            $values = null;
        }

        // 数据库中的显式值（含 false）优先；空串视为未覆盖，回落到 config
        if (is_array($values) && array_key_exists($field, $values) && $values[$field] !== null && $values[$field] !== '') {
            return $values[$field];
        }

        return config("{$configPrefix}.{$field}", $default);
    }
}

if (! function_exists('site_locales')) {
    function site_locales(): array
    {
        return config('site.locales', ['en']);
    }
}

if (! function_exists('default_locale')) {
    function default_locale(): string
    {
        return config('site.default_locale', 'en');
    }
}

if (! function_exists('is_rtl_locale')) {
    function is_rtl_locale(?string $locale = null): bool
    {
        return in_array($locale ?? app()->getLocale(), config('site.rtl_locales', []), true);
    }
}

if (! function_exists('localized_route')) {
    /**
     * 按语言生成命名路由 URL：默认语言不带前缀，其余带 /es/ 等前缀。
     */
    function localized_route(string $name, array $params = [], ?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $name = ($locale === default_locale() ? '' : $locale.'.').$name;

        return route($name, $params);
    }
}

if (! function_exists('locale_alternates')) {
    /**
     * 生成某页面在所有语言下的 URL 映射（hreflang 用）。
     *
     * @return array<string, string>
     */
    function locale_alternates(string $name, array $params = []): array
    {
        $alternates = [];
        foreach (site_locales() as $locale) {
            $alternates[$locale] = localized_route($name, $params, $locale);
        }

        return $alternates;
    }
}
