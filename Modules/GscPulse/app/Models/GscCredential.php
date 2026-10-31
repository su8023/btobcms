<?php

namespace Modules\GscPulse\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Google Search Console OAuth 凭据（每站点属性一行）。
 * OAuth 全流程手写（无 SDK），token 的换取/刷新见 GscFetcher 与回调控制器。
 */
class GscCredential extends Model
{
    protected $fillable = ['site_url', 'access_token', 'refresh_token', 'token_expires_at'];

    protected function casts(): array
    {
        return [
            'token_expires_at' => 'datetime',
            // OAuth token 属长期凭据：加密落库（APP_KEY 驱动），DB 泄露不直接泄露授权
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
        ];
    }

    /**
     * 默认站点属性：优先后台设置的 site_url，缺省取站点根地址并补全尾斜杠
     * （GSC 属性约定形如 https://www.aples.xyz/，带尾斜杠）。
     */
    public static function defaultSiteUrl(): string
    {
        return self::normalizeSiteUrl(
            (string) plugin_setting('gsc-pulse', 'site_url', rtrim(config('app.url'), '/').'/')
        );
    }

    /**
     * 站点属性归一化：补全尾斜杠（GSC 约定形如 https://www.example.com/）。
     */
    public static function normalizeSiteUrl(string $siteUrl): string
    {
        return rtrim(trim($siteUrl), '/').'/';
    }

    /**
     * 站点属性格式校验：必须是完整的 http/https 绝对地址。
     *
     * WHY：空值会被 OAuthClient 存成 '/'，进而拼出 /webmasters/v3/sites//searchAnalytics/query
     * 这种无效请求路径；相对路径或缺协议的地址同样会被 GSC 拒绝。
     */
    public static function isValidSiteUrl(string $siteUrl): bool
    {
        $siteUrl = trim($siteUrl);

        if ($siteUrl === '') {
            return false;
        }

        $parts = parse_url($siteUrl);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true) || $parts['host'] === '') {
            return false;
        }

        return filter_var($siteUrl, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * 当前生效凭据：按默认站点属性取一行；未连接时返回 null。
     */
    public static function current(?string $siteUrl = null): ?self
    {
        return static::query()->where('site_url', $siteUrl ?? static::defaultSiteUrl())->first();
    }

    /**
     * 是否已连接（存在凭据且持有 refresh_token，可刷新出可用 access_token）。
     */
    public static function isConnected(?string $siteUrl = null): bool
    {
        $credential = static::current($siteUrl);

        return $credential !== null && $credential->refresh_token !== null && $credential->refresh_token !== '';
    }
}
