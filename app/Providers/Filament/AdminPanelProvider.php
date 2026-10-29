<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName(fn () => site_setting('site.name', config('site.name', 'BtoBcms')))
            ->login()
            ->userMenuItems([
                'locale' => MenuItem::make()
                    ->label(fn (): string => app()->getLocale() === 'zh_CN' ? 'English' : '中文')
                    ->url(fn (): string => route('admin.locale', app()->getLocale() === 'zh_CN' ? 'en' : 'zh_CN'))
                    ->icon(Heroicon::OutlinedLanguage)
                    ->sort(9),
            ])
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            // 插件 CRUD 资源：约定式发现 Modules/{Name}/app/Filament/Resources/*Resource.php
            ->resources(\App\Support\PluginPages::discoverResources())
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            // 插件后台页面：约定式自动发现 Modules/{Name}/app/Filament/Pages/*.php
            // 插件自带后台页零核心改动；模块停用后 Provider 不加载，页面自动消失
            ->pages(array_merge(
                [Dashboard::class],
                \App\Support\PluginPages::discover(),
            ))
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                // 登录第一眼：新手开始清单（sort=-4 排最前）。
                // FilamentInfoWidget（英文开发者信息卡）刻意不注册——对运营是噪音。
                \App\Filament\Widgets\WelcomeChecklistWidget::class,
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                \App\Http\Middleware\SetAdminLocale::class,
            ]);
    }
}
