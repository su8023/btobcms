<?php

namespace Modules\GscPulse\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;

/**
 * Google OAuth 客户端凭据读写。
 *
 * client_secret 是长效机密：拿到它就能冒充本站的 OAuth 应用发起授权流程。
 * 它此前以明文存进 settings 表的 JSON，并在后台页面以 type="text" 明文回显，
 * 页面源码 / 截图 / 浏览器缓存都会泄密。
 *
 * 现在统一用 Crypt 加密后存储，读取时解密；输出端一律不回填原值。
 */
class OAuthClient
{
    /** 加密值前缀：用于识别密文与历史明文（明文兼容读取） */
    private const ENC_PREFIX = 'enc:';

    /** settings 表中的键 */
    private const SETTING_KEY = 'plugin.GscPulse';

    public static function clientId(): string
    {
        $values = self::values();

        return trim((string) ($values['client_id'] ?? ''));
    }

    /**
     * 取 client_secret（自动解密；历史明文值原样返回，兼容升级前存量数据）。
     */
    public static function clientSecret(): string
    {
        $values = self::values();
        $raw = (string) ($values['client_secret'] ?? '');

        if ($raw === '') {
            return '';
        }

        if (! str_starts_with($raw, self::ENC_PREFIX)) {
            // 升级前的明文存量
            return trim($raw);
        }

        try {
            return trim(Crypt::decryptString(substr($raw, strlen(self::ENC_PREFIX))));
        } catch (\Throwable) {
            // APP_KEY 被换过导致解不开：视为未配置，不要把密文当密码发出去
            return '';
        }
    }

    /**
     * 是否已配置完整的客户端凭据。
     */
    public static function isConfigured(): bool
    {
        return self::clientId() !== '' && self::clientSecret() !== '';
    }

    /**
     * 保存客户端凭据与站点属性（client_secret 加密后入库）。
     *
     * @param  string  $clientSecret  传空串表示「不修改已保存的 secret」
     */
    public static function save(string $clientId, string $clientSecret, string $siteUrl): void
    {
        $values = self::values();

        $values['client_id'] = trim($clientId);

        $secret = trim($clientSecret);

        if ($secret !== '') {
            $values['client_secret'] = self::ENC_PREFIX.Crypt::encryptString($secret);
        } elseif (! isset($values['client_secret'])) {
            $values['client_secret'] = '';
        }

        // 站点属性统一补尾斜杠（GSC 约定形如 https://www.example.com/）
        $values['site_url'] = rtrim(trim($siteUrl), '/').'/';

        Setting::put(self::SETTING_KEY, $values);
    }

    /**
     * @return array<string, mixed>
     */
    private static function values(): array
    {
        $values = Setting::get(self::SETTING_KEY, []);

        return is_array($values) ? $values : [];
    }
}
