<?php

namespace Modules\GscPulse\Filament\Pages;

use App\Models\Setting;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Notifications\Notification;
use Modules\GscPulse\Models\GscCredential;

/**
 * GSC 连接设置页（/admin/gsc-pulse-connect）：
 * OAuth 应用凭据（client_id/secret）与站点属性 site_url 的填写保存、
 * 授权状态展示、「连接 Google」跳转与「断开」。无 SDK，全部手写 OAuth 2.0。
 */
class GscConnectPage extends Page
{
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
        return (string) plugin_setting('gsc-pulse', 'client_id', '') !== ''
            && (string) plugin_setting('gsc-pulse', 'client_secret', '') !== '';
    }

    /** Google OAuth 授权跳转地址（readonly scope + 离线刷新 + 每次强弹同意以拿到 refresh_token） */
    public function getAuthUrlProperty(): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => (string) plugin_setting('gsc-pulse', 'client_id', ''),
            'redirect_uri' => route('gsc-pulse.callback'),
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/webmasters.readonly',
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
        if (trim($this->clientId) === '' || trim($this->clientSecret) === '') {
            Notification::make()->title('Client ID 与 Client Secret 必填')->danger()->send();

            return;
        }

        $values = Setting::get('plugin.GscPulse', []) ?: [];
        $values['client_id'] = trim($this->clientId);
        $values['client_secret'] = trim($this->clientSecret);
        // 站点属性统一补尾斜杠（GSC 约定形如 https://www.aples.xyz/）
        $values['site_url'] = rtrim(trim($this->siteUrl), '/').'/';

        Setting::put('plugin.GscPulse', $values);

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
        $this->clientId = (string) plugin_setting('gsc-pulse', 'client_id', '');
        $this->clientSecret = (string) plugin_setting('gsc-pulse', 'client_secret', '');
        $this->siteUrl = (string) plugin_setting('gsc-pulse', 'site_url', rtrim(config('app.url'), '/').'/');
    }
}
