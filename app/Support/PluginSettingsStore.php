<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Lang;

/**
 * 插件设置的共享存储服务：字段 schema 访问、当前生效值解析、保存归一化。
 *
 * 语义与原 PluginCenter 内联设置面板保持一致（唯一实现，独立设置页与测试共用）：
 * - 预填：数据库值 → config 回退（boolean 缺省 true、其余 ''），boolean 用 filter_var 归一；
 *   空字符串视为"未覆盖"（plugin_setting 运行时回落 config）。
 * - 保存：boolean 强转 (bool)（显式 false 必须生效）；number 空串存 ''、数字 0+raw、其余 trim；
 *   text/textarea/password/select trim；required 缺失则拒绝落库并返回字段 label。
 */
class PluginSettingsStore
{
    /**
     * 插件 schema 类名：Modules\{Name}\Settings\PluginSettings 存在则返回，否则 null。
     */
    public static function schemaClass(string $moduleName): ?string
    {
        $class = "Modules\\{$moduleName}\\Settings\\PluginSettings";

        return class_exists($class) ? $class : null;
    }

    public static function hasSettings(string $moduleName): bool
    {
        return static::schemaClass($moduleName) !== null;
    }

    /**
     * schema 声明的 configPrefix（如 'feishu-notifier'）；无 schema 时 null。
     */
    public static function configPrefix(string $moduleName): ?string
    {
        $class = static::schemaClass($moduleName);

        return $class === null ? null : $class::configPrefix();
    }

    /**
     * schema 字段声明（原样返回）。
     *
     * @return list<array<string, mixed>>
     */
    public static function fields(string $moduleName): array
    {
        $class = static::schemaClass($moduleName);

        if ($class === null) {
            return [];
        }

        return $class::fields();
    }

    /**
     * 字段显示标签：lang 键 admin.plugins.fields.{prefix}.{key} 存在则翻译，否则用 schema 原文。
     *
     * @param  array<string, mixed>  $field
     */
    public static function label(string $moduleName, array $field): string
    {
        $prefix = static::configPrefix($moduleName);
        $langKey = "admin.plugins.fields.{$prefix}.{$field['key']}";

        return Lang::has($langKey) ? __($langKey) : (string) $field['label'];
    }

    /**
     * 当前生效值（键为字段 key）：数据库 → config 回退 → boolean 归一。
     * 与原 PluginCenter::settingsFields 的取值逻辑一致。
     *
     * @return array<string, mixed>
     */
    public static function values(string $moduleName): array
    {
        $class = static::schemaClass($moduleName);

        if ($class === null) {
            return [];
        }

        $prefix = $class::configPrefix();
        $stored = Setting::get("plugin.{$moduleName}", []) ?: [];
        $values = [];

        foreach ($class::fields() as $field) {
            $key = $field['key'];

            if (array_key_exists($key, $stored) && $stored[$key] !== null) {
                $value = $stored[$key];
            } else {
                $value = config("{$prefix}.{$key}", $field['type'] === 'boolean' ? true : '');
            }

            if ($field['type'] === 'boolean') {
                $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            }

            $values[$key] = $value;
        }

        return $values;
    }

    /**
     * 保存归一化（与原 PluginCenter::saveSettings 语义一致）：
     * - boolean 强转 (bool)：显式 false 生效，不被视为"未设置"；
     * - number：空串/null 存 ''，数字 0+raw（int/float 归一），非数字 trim；
     * - text/textarea/password/select：trim((string) raw)；
     * - required 缺失（'' 或 null）：不落库，ok=false 且 error=字段 label（schema 原文，
     *   与原面板通知一致）；空字符串在 plugin_setting 读取时回落 config。
     *
     * @param  array<string, mixed>  $input
     * @return array{ok: bool, error: ?string, saved?: array<string, mixed>}
     *         ok=false 且 error=null 表示 moduleName 无 schema（调用方应已校验）。
     */
    public static function save(string $moduleName, array $input): array
    {
        $class = static::schemaClass($moduleName);

        if ($class === null) {
            return ['ok' => false, 'error' => null];
        }

        $out = [];

        foreach ($class::fields() as $field) {
            $key = $field['key'];
            $raw = $input[$key] ?? null;

            $value = match ($field['type']) {
                'boolean' => (bool) $raw,
                'number' => ($raw === null || $raw === '') ? '' : (is_numeric($raw) ? 0 + $raw : trim((string) $raw)),
                default => trim((string) ($raw ?? '')),
            };

            if (! empty($field['required']) && ($value === '' || $value === null)) {
                return ['ok' => false, 'error' => (string) $field['label']];
            }

            $out[$key] = $value;
        }

        Setting::put("plugin.{$moduleName}", $out);

        return ['ok' => true, 'error' => null, 'saved' => $out];
    }
}
