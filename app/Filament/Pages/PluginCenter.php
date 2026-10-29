<?php

namespace App\Filament\Pages;

use BackedEnum;
use App\Models\Setting;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Artisan;
use Nwidart\Modules\Facades\Module;
use UnitEnum;

/**
 * 插件中心：按类别展示全部已安装插件，支持界面启停。
 * 插件在 module.json 中声明 category（notification/ai/service/marketing/seo/integration/general）。
 */
class PluginCenter extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static ?int $navigationSort = 40;

    protected string $view = 'filament.pages.plugin-center';

    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('runCheck')
                ->label(__('admin.plugins.run_check'))
                ->icon('heroicon-o-shield-check')
                ->color('gray')
                ->action(function (): void {
                    Artisan::call('plugins:verify');
                    $output = trim(Artisan::output());

                    // 提取各插件的检查行（含 ✓/⚠/✗ 标记）与汇总行
                    $lines = array_values(array_filter(
                        array_map('trim', explode("\n", $output)),
                        fn (string $l) => $l !== ''
                            && (str_contains($l, '✓') || str_contains($l, '⚠') || str_contains($l, '✗') || str_contains($l, '插件可用性')),
                    ));

                    Notification::make()
                        ->title(__('admin.plugins.check_done'))
                        ->body(implode("\n", array_slice($lines, 0, 14)))
                        ->persistent()
                        ->info()
                        ->send();
                }),

            \Filament\Actions\Action::make('uploadPlugin')
                ->label(__('admin.plugins.upload'))
                ->icon('heroicon-o-arrow-up-tray')
                ->form([
                    \Filament\Forms\Components\FileUpload::make('zip')
                        ->label(__('admin.plugins.upload_file'))
                        ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed'])
                        ->disk('local')
                        ->directory('plugin-uploads')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $zipPath = \Storage::disk('local')->path($data['zip']);

                    $result = app(\App\Services\PluginUploader::class)->upload($zipPath);

                    \Storage::disk('local')->delete($data['zip']);

                    if (! $result['ok']) {
                        Notification::make()
                            ->title(__('admin.plugins.upload_failed'))
                            ->body($result['error'])
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('admin.plugins.upload_success', [
                            'name' => $result['name'],
                            'category' => $result['category'] ?? 'general',
                            'version' => $result['version'] ?? '—',
                        ]))
                        ->body(implode("\n", array_slice($result['steps'], -3))."\n\n".__('admin.plugins.upload_hint'))
                        ->success()
                        ->persistent()
                        ->send();
                }),
        ];
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('admin.groups.plugins');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.plugins.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('admin.plugins.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('admin.plugins.subtitle');
    }

    /**
     * 全部插件按类别分组（类别按固定顺序，未声明的归 general）。
     *
     * @return array<string, list<array{name: string, description: string, version: string, category: string, enabled: bool}>>
     */
    public function getPlugins(): array
    {
        $order = ['notification', 'ai', 'service', 'marketing', 'seo', 'integration', 'growth', 'productivity', 'performance', 'system', 'general'];
        $grouped = [];

        /** @var \Nwidart\Modules\Module $module */
        foreach (Module::all() as $module) {
            $manifest = json_decode((string) file_get_contents($module->getPath().'/module.json'), true) ?: [];

            $category = (string) ($manifest['category'] ?? 'general');
            if (! in_array($category, $order, true)) {
                $category = 'general';
            }

            $grouped[$category][] = [
                'name' => $module->getName(),
                'description' => (string) ($manifest['description'] ?? ''),
                'version' => (string) ($manifest['version'] ?? '—'),
                'category' => $category,
                'enabled' => $module->isEnabled(),
            ];
        }

        uksort($grouped, fn (string $a, string $b) => array_search($a, $order, true) <=> array_search($b, $order, true));

        return $grouped;
    }

    /**
     * 启停插件（写 modules_statuses.json，下一个请求生效）。
     */
    public function toggle(string $name): void
    {
        $module = Module::find($name);

        if ($module === null) {
            return;
        }

        $module->isEnabled()
            ? $module->disable()
            : $module->enable();

        Notification::make()
            ->title(__('admin.plugins.toggled', [
                'name' => $name,
                'state' => $module->isEnabled()
                    ? __('admin.plugins.state_enabled')
                    : __('admin.plugins.state_disabled'),
            ]))
            ->body(__('admin.plugins.toggle_hint'))
            ->success()
            ->send();
    }

    /* ----------------------------------------------------------
     | 插件设置：卡片链接到独立设置页 /admin/plugins/{plugin}/settings
     * --------------------------------------------------------- */

    public function hasSettings(string $name): bool
    {
        return \App\Support\PluginSettingsStore::hasSettings($name);
    }

    public function settingsUrl(string $name): string
    {
        return \App\Filament\Pages\PluginSettingsPage::getUrl(['plugin' => $name]);
    }

    /* ----------------------------------------------------------
     | 插件使用教程：约定 Modules\{Name}\Support\Docs::content()
     * --------------------------------------------------------- */

    public function hasDocs(string $name): bool
    {
        return \App\Support\PluginDocs::has($name);
    }

    public function docs(string $name): string
    {
        return (string) \App\Support\PluginDocs::content($name);
    }
}
