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
     * 记录一次授权尝试的结果（成功/失败 + 原因 + 时间），在连接页常驻显示。
     *
     * WHY 持久化：回调的失败原因此前只走一次性 flash 提示，管理员没看到或刷新掉就永久丢失，
     * 而共享主机用户看不了 laravel.log——排障只能瞎猜。落库后任何一次尝试都可追溯。
     */
    public static function recordAttempt(bool $ok, string $message): void
    {
        $values = self::values();
        $values['last_attempt'] = [
            'at' => now()->format('Y-m-d H:i'),
            'ok' => $ok,
            'message' => mb_substr(trim($message), 0, 300),
        ];

        Setting::put(self::SETTING_KEY, $values);
    }

    /**
     * 最近一次授权尝试记录；从未尝试过返回 null。
     *
     * @return array{at: string, ok: bool, message: string}|null
     */
    public static function lastAttempt(): ?array
    {
        $attempt = self::values()['last_attempt'] ?? null;

        if (! is_array($attempt) || ! isset($attempt['at'], $attempt['ok'], $attempt['message'])) {
            return null;
        }

        return $attempt;
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
