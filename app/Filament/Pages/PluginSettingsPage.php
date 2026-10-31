<?php

namespace App\Filament\Pages;

use App\Support\PluginSettingsStore;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Pages\PageConfiguration;
use Filament\Panel;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Route;
use Nwidart\Modules\Facades\Module;
use UnitEnum;

/**
 * 插件独立设置页：/admin/plugins/{plugin}/settings（从插件中心卡片链入，不进侧边栏）。
 * 表单字段由插件 schema（Modules\{Name}\Settings\PluginSettings）动态生成，
 * 读写经 PluginSettingsStore（语义与原 PluginCenter 内联面板一致）。
 */
class PluginSettingsPage extends Page
{
    /**
     * 仅管理员可访问：插件设置可写 Webhook 地址与 LLM base_url，
     * 等于把密钥与外发请求的落点交给普通编辑账号。
     */
    public static function canAccess(): bool
    {
        return user_is_admin();
    }

    /** 当前插件模块名（路由参数 {plugin}，如 FeishuNotifier） */
    public ?string $plugin = null;

    /** @var array<string, mixed> | null 表单状态（statePath: data） */
    public ?array $data = [];

    /** 不进侧边栏：仅从插件中心卡片链入 */
    protected static bool $shouldRegisterNavigation = false;

    /* ----------------------------------------------------------
     | 路由：/admin/plugins/{plugin}/settings（带参数页面路由）
     * --------------------------------------------------------- */

    public static function routes(Panel $panel, ?PageConfiguration $configuration = null): void
    {
        $middleware = static::getRouteMiddleware($panel);

        if ($configuration) {
            $middleware = [
                ...$middleware,
                "page-configuration:{$configuration->getKey()}",
            ];
        }

        Route::get(static::getRoutePath($panel), static::class)
            ->middleware($middleware)
            ->withoutMiddleware(static::getWithoutRouteMiddleware($panel))
            ->name(static::getRelativeRouteName($panel));
    }

    public static function getRoutePath(Panel $panel): string
    {
        return '/plugins/{plugin}/settings';
    }

    public static function getRelativeRouteName(Panel $panel): string
    {
        return 'plugins.settings';
    }

    /* ----------------------------------------------------------
     | 页面
     * --------------------------------------------------------- */

    public function mount(string $plugin): void
    {
        if (Module::find($plugin) === null || ! PluginSettingsStore::hasSettings($plugin)) {
            abort(404);
        }

        $this->plugin = $plugin;

        $this->form->fill(PluginSettingsStore::values($plugin));
    }

    public function getTitle(): string|Htmlable
    {
        return ($this->plugin ?? __('admin.plugins.title')).' — '.__('admin.plugins.settings');
    }

    /**
     * 页头副标题：类别 · 版本 · 启用状态（信息取自 module.json）。
     */
    public function getSubheading(): string|Htmlable|null
    {
        if ($this->plugin === null) {
            return null;
        }

        $module = Module::find($this->plugin);
        $manifest = $module === null
            ? []
            : (json_decode((string) file_get_contents($module->getPath().'/module.json'), true) ?: []);

        $category = __('admin.plugins.categories.'.($manifest['category'] ?? 'general'));
        $version = (string) ($manifest['version'] ?? '—');
        $state = $module !== null && $module->isEnabled()
            ? __('admin.plugins.state_enabled')
            : __('admin.plugins.state_disabled');

        return "{$category} · v{$version} · {$state}";
    }

    protected function getHeaderActions(): array
    {
        $module = Module::find($this->plugin ?? '');
        $enabled = $module !== null && $module->isEnabled();

        return [
            Action::make('togglePlugin')
                ->label($enabled ? __('admin.plugins.disable') : __('admin.plugins.enable'))
                ->color($enabled ? 'danger' : 'success')
                ->action(fn () => $this->togglePlugin()),
        ];
    }

    /**
     * 启停插件（逻辑与 PluginCenter::toggle 一致：Module enable/disable + 通知）。
     */
    public function togglePlugin(): void
    {
        if ($this->plugin === null) {
            return;
        }

        $module = Module::find($this->plugin);

        if ($module === null) {
            return;
        }

        $module->isEnabled()
            ? $module->disable()
            : $module->enable();

        Notification::make()
            ->title(__('admin.plugins.toggled', [
                'name' => $this->plugin,
                'state' => $module->isEnabled()
                    ? __('admin.plugins.state_enabled')
                    : __('admin.plugins.state_disabled'),
            ]))
            ->body(__('admin.plugins.toggle_hint'))
            ->success()
            ->send();
    }

    /* ----------------------------------------------------------
     | 表单：schema 字段动态生成，初始值来自 PluginSettingsStore::values()
     * --------------------------------------------------------- */

    public function form(Schema $schema): Schema
    {
        $prefix = PluginSettingsStore::configPrefix($this->plugin ?? '') ?? '';

        // 按 schema 的 section 键分组渲染（无 section 的归入 general 单组，不再包 Section 壳）
        $grouped = collect(PluginSettingsStore::fields($this->plugin ?? ''))
            ->groupBy(fn (array $field) => $field['section'] ?? 'general');

        $singleGroup = $grouped->count() === 1 && $grouped->keys()->first() === 'general';

        $components = $grouped->map(function ($fields, string $sectionKey) use ($prefix, $singleGroup) {
            $fieldComponents = collect($fields)
                ->map(fn (array $field) => $this->makeFieldComponent($field))
                ->all();

            if ($singleGroup) {
                return $fieldComponents;
            }

            $langKey = "admin.plugins.sections.{$prefix}.{$sectionKey}";
            $label = \Illuminate\Support\Facades\Lang::has($langKey)
                ? __($langKey)
                : ucfirst(str_replace('-', ' ', $sectionKey));

            return Section::make($label)->schema($fieldComponents)->columns(1);
        })
        ->flatten()
        ->all();

        return $schema
            ->components($components)
            ->statePath('data');
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function makeFieldComponent(array $field): Field
    {
        $key = (string) $field['key'];
        $placeholder = $field['placeholder'] ?? null;
        $hasPlaceholder = is_string($placeholder) && $placeholder !== '';

        $component = match ($field['type']) {
            'boolean' => Toggle::make($key),
            'select' => Select::make($key)->options($field['options'] ?? []),
            'textarea' => Textarea::make($key)->rows(3),
            'password' => TextInput::make($key)->password()->revealable(),
            'number' => TextInput::make($key)->numeric(),
            default => TextInput::make($key),
        };

        // Toggle/Select 无 placeholder 概念，仅文本类组件应用
        if ($hasPlaceholder && in_array($field['type'], ['text', 'password', 'number', 'textarea'], true)) {
            $component->placeholder($placeholder);
        }

        return $component
            ->label(PluginSettingsStore::label($this->plugin ?? '', $field));
    }

    /* ----------------------------------------------------------
     | 保存：经 PluginSettingsStore 归一化后落库
     * --------------------------------------------------------- */

    public function save(): void
    {
        if ($this->plugin === null) {
            return;
        }

        $result = PluginSettingsStore::save($this->plugin, $this->form->getState());

        if ($result['ok']) {
            Notification::make()
                ->title(__('admin.plugins.settings_saved', ['name' => $this->plugin]))
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title(__('admin.plugins.settings_required', ['label' => (string) ($result['error'] ?? '')]))
                ->danger()
                ->send();
        }
    }

    public function content(Schema $schema): Schema
    {
        $docs = $this->plugin !== null ? \App\Support\PluginDocs::content($this->plugin) : null;

        $components = [];

        // 插件使用教程（约定 Modules\{Name}\Support\Docs）：设置表单上方折叠展示
        if ($docs !== null) {
            $components[] = Section::make('📖 使用教程')
                ->description('这个插件是什么、怎么配置、日常怎么用')
                ->collapsible()
                ->persistCollapsed()
                ->schema([
                    \Filament\Schemas\Components\Html::make($docs),
                ]);
        }

        $components[] = Form::make([EmbeddedSchema::make('form')])
            ->id('form')
            ->livewireSubmitHandler('save')
            ->footer([
                Actions::make([
                    Action::make('save')
                        ->label(__('admin.plugins.settings_save'))
                        ->action('save'),
                ]),
            ]);

        return $schema->components($components);
    }
}
