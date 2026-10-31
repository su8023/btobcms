<?php

namespace Modules\GscPulse\Filament\Pages;

use App\Models\Setting;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Modules\GscPulse\Models\GscCredential;
use Modules\GscPulse\Support\OAuthClient;

/**
 * GSC 连接设置页（/admin/gsc-pulse-connect）：
 * OAuth 应用凭据（client_id/secret）与站点属性 site_url 的填写保存、
 * 授权状态展示、「连接 Google」跳转与「断开」。无 SDK，全部手写 OAuth 2.0。
 */
class GscConnectPage extends Page
{
    /**
     * 仅管理员可访问：本页保存 Google OAuth client_secret，必须按机密凭据管控。
     */
    public static function canAccess(): bool
    {
        return user_is_admin();
    }

    /**
     * 模块自身开关是否打开（与「模块是否被核心发现」无关）。
     */
    public static function isEnabled(): bool
    {
        return (bool) config('gsc-pulse.enabled', true);
    }

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-link';

    protected static ?int $navigationSort = 39;

    protected static ?string $slug = 'gsc-pulse-connect';

    protected string $view = 'gscpulse::gsc-connect';

    /** Livewire 表单属性（填写区，保存前不影响已生效的连接） */
    public string $clientId = '';

    public string $clientSecret = '';

    public string $siteUrl = '';

    public function mount(): void
    {
        $this->fillFromSettings();

        // OAuth state 防 CSRF：一次性存入 session，回调处 hash_equals 校验后取出即焚
        session()->put('gsc-pulse.state', csrf_token());
    }

    /**
     * 功能未启用时改用「未启用」视图。
     *
     * WHY：后台页面由核心按「模块启用」发现（App\Support\PluginPages），与本插件自己的
     * enabled 开关无关——开关关掉后页面仍在导航里。原页面模板里要 route('gsc-pulse.callback')，
     * 一旦该路由没注册就会抛 RouteNotFoundException → 后台 500。改走独立视图，
     * 既不渲染授权入口，也不会碰回调路由。
     */
    public function getView(): string
    {
        return self::isEnabled() ? $this->view : 'gscpulse::gsc-disabled';
    }

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return __('admin.groups.growth');
    }

    public static function getNavigationLabel(): string
    {
        return 'GSC 连接';
    }

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return 'GSC 连接（Google Search Console）';
    }

    /* ----------------------------------------------------------
     | 计算属性（blade 直接可用：$this->credential 等）
     * --------------------------------------------------------- */

    /** 当前站点属性的凭据行；未连接为 null */
    public function getCredentialProperty(): ?GscCredential
    {
        return GscCredential::current();
    }

    /** 是否已连接（有凭据且带 refresh_token） */
    public function getIsConnectedProperty(): bool
    {
        return GscCredential::isConnected();
    }

    /** 已保存的 OAuth 应用凭据是否齐全（决定「连接 Google」按钮是否可用） */
    public function getAuthReadyProperty(): bool
    {
        return OAuthClient::isConfigured();
    }

    /** Google OAuth 授权跳转地址（readonly scope + 离线刷新 + 每次强弹同意以拿到 refresh_token） */
    public function getAuthUrlProperty(): string
    {
        if (! self::isEnabled()) {
            return ''; // 未启用：不生成授权链接（同时避免 route() 在路由缺失时抛异常）
        }

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => (string) plugin_setting('gsc-pulse', 'client_id', ''),
            'redirect_uri' => route('gsc-pulse.callback'),
            'response_type' => 'code',
            'scope' => (string) config('gsc-pulse.scope', 'https://www.googleapis.com/auth/webmasters.readonly'),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => (string) session('gsc-pulse.state', csrf_token()),
        ]);
    }

    /* ----------------------------------------------------------
     | 操作
     * --------------------------------------------------------- */

    /**
     * 保存 OAuth 应用凭据与站点属性：写入插件设置（plugin.GscPulse），
     * 不动 gsc_credentials（连接状态由授权回调写入）。
     */
    public function save(): void
    {
        if (trim($this->clientId) === '') {
            Notification::make()->title('Client ID 必填')->danger()->send();

            return;
        }

        // client_secret 留空表示「沿用已保存的密文」，不再强制重填
        if (trim($this->clientSecret) === '' && ! OAuthClient::isConfigured()) {
            Notification::make()->title('Client Secret 必填')->danger()->send();

            return;
        }

        // 站点属性必须是完整的 http/https 绝对地址：
        // 空值会被存成 '/'，进而拼出 /webmasters/v3/sites//searchAnalytics/query 这种无效路径，
        // 相对路径同样会被 GSC 拒绝——先拦在这里，别让错误配置留到授权回调才暴露。
        $siteUrl = trim($this->siteUrl);

        if ($siteUrl === '') {
            Notification::make()
                ->title('GSC 站点属性必填')
                ->body('请填写 Search Console 里的资源网址（如 https://www.example.com/），留空会生成无效的请求路径。')
                ->danger()
                ->send();

            return;
        }

        if (! GscCredential::isValidSiteUrl($siteUrl)) {
            Notification::make()
                ->title('GSC 站点属性格式无效')
                ->body('必须是完整的 http/https 绝对地址（如 https://www.example.com/），不能是相对路径或缺少协议。')
                ->danger()
                ->send();

            return;
        }

        OAuthClient::save($this->clientId, $this->clientSecret, $siteUrl);

        // 保存后不回填密文：避免 Livewire 快照 / 页面源码泄露
        $this->clientSecret = '';

        Notification::make()->title('OAuth 凭据已保存，可点击「连接 Google」完成授权')->success()->send();
    }

    /**
     * 断开连接：删除凭据行（保留 client_id/secret 设置，方便重连）。
     */
    public function disconnect(): void
    {
        GscCredential::query()->where('site_url', GscCredential::defaultSiteUrl())->delete();

        Notification::make()->title('已断开 Google Search Console 连接')->success()->send();
    }

    /**
     * 表单回填：数据库插件设置优先，缺省回落站点根地址。
     */
    protected function fillFromSettings(): void
    {
        $this->clientId = OAuthClient::clientId();
        // 密文不回填到表单：只提示「已保存」，需要时由管理员重新输入覆盖
        $this->clientSecret = '';
        $this->siteUrl = (string) plugin_setting('gsc-pulse', 'site_url', rtrim(config('app.url'), '/').'/');
    }
}
