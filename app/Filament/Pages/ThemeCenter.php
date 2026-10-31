<?php

namespace App\Filament\Pages;

use App\Services\ThemeManager;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use UnitEnum;

/**
 * 主题中心：按主题列出（激活/删除）、上传主题 ZIP 包。
 * 主题结构约定见 docs/theme-development.md 与 app/Services/ThemeManager.php 头注释。
 */
class ThemeCenter extends Page
{
    /**
     * 仅管理员可访问：主题安装会向 public/ 写入文件，属于任意文件写。
     */
    public static function canAccess(): bool
    {
        return user_is_admin();
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPaintBrush;

    protected static ?int $navigationSort = 41;

    protected string $view = 'filament.pages.theme-center';

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('admin.groups.themes');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.themes.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('admin.themes.title');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('admin.themes.subtitle');
    }

    public function getThemes(): array
    {
        return app(ThemeManager::class)->all();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('uploadTheme')
                ->label(__('admin.themes.upload'))
                ->icon('heroicon-o-arrow-up-tray')
                ->form([
                    FileUpload::make('zip')
                        ->label(__('admin.themes.upload_file'))
                        ->acceptedFileTypes(['application/zip', 'application/x-zip-compressed'])
                        ->disk('local')
                        ->directory('theme-uploads')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $zipPath = \Storage::disk('local')->path($data['zip']);

                    $result = app(ThemeManager::class)->install($zipPath);

                    \Storage::disk('local')->delete($data['zip']);

                    if (! $result['ok']) {
                        Notification::make()
                            ->title(__('admin.themes.upload_failed'))
                            ->body($result['error'])
                            ->danger()
                            ->persistent()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('admin.themes.upload_success', ['name' => $result['name']]))
                        ->body(__('admin.themes.upload_hint'))
                        ->success()
                        ->persistent()
                        ->send();
                }),
        ];
    }

    public function activate(string $slug): void
    {
        $result = app(ThemeManager::class)->activate($slug);

        $result['ok']
            ? Notification::make()->title(__('admin.themes.activated', ['name' => $slug]))->success()->send()
            : Notification::make()->title($result['error'])->danger()->send();
    }

    public function deleteTheme(string $slug): void
    {
        $result = app(ThemeManager::class)->delete($slug);

        $result['ok']
            ? Notification::make()->title(__('admin.themes.deleted', ['name' => $slug]))->success()->send()
            : Notification::make()->title($result['error'])->danger()->send();
    }
}
