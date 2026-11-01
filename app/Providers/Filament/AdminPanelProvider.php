<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Navigation\NavigationGroup;
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
            // 管理员资料/改密码页：此前未注册（/admin/profile 404），后台没有改密码入口
            ->profile()
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
            // 侧边栏分组固定顺序（不声明时按字母序，"增长"里的 10 个数据页挤成一团）：
            // 数据看板类独立成组（growth_data），获客工具留在增长组，切换才有逻辑层次。
            ->navigationGroups([
                NavigationGroup::make()->label(__('admin.groups.catalog')),
                NavigationGroup::make()->label(__('admin.groups.content')),
                NavigationGroup::make()->label(__('admin.groups.growth')),
                NavigationGroup::make()->label(__('admin.groups.growth_data')),
                NavigationGroup::make()->label(__('admin.groups.system')),
                NavigationGroup::make()->label(__('admin.groups.plugins')),
                NavigationGroup::make()->label(__('admin.groups.themes')),
            ])
            // 插件页组件样式：Filament 预编译 CSS 不含应用自定义的 Tailwind 类，
            // 指标卡/空状态/表单控件用本文件手写样式（CSS 变量驱动，暗色自动适配）。
            // 带文件 mtime 版本号：发版后浏览器不再拿旧缓存（曾实测：更新中心改版被
            // 旧 CSS 缓存干扰，新样式要强刷才出来）。
            ->assets([
                \Filament\Support\Assets\Css::make('admin-plugins', asset('css/admin-plugins.css').'?v='.($cssVer = @filemtime(public_path('css/admin-plugins.css')) ?: 0)),
            ])
            // 后台报错提示带上真实原因（v1.4.29）：
            // Filament 默认只说「页面加载出错，请稍后重试」——管理员无从判断发生了什么，
            // 排障只能靠猜。这里把排查入口写进提示正文（真实细节已写入 laravel.log）。
            ->registerErrorNotification(
                '页面加载出错',
                '请求未能完成。原因已记入 storage/logs/laravel.log，请把该文件里的「[后台请求失败]」记录发给技术人员。',
            )
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
