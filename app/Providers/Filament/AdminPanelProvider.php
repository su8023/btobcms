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
use Filament\Widgets\FilamentInfoWidget;
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
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages(array_values(array_filter([
                Dashboard::class,
                // SEO 巡检报告页仅在其插件启用时注册
                \Nwidart\Modules\Facades\Module::isEnabled('AiSeoAuditor')
                    ? \Modules\AiSeoAuditor\Filament\Pages\SeoAuditReports::class
                    : null,
                // 更新中心页仅在其插件启用时注册
                \Nwidart\Modules\Facades\Module::isEnabled('UpdateCenter')
                    ? \Modules\UpdateCenter\Filament\Pages\UpdateCenterPage::class
                    : null,
            ])))
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
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
