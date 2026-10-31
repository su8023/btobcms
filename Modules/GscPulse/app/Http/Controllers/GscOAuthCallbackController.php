<?php

namespace Modules\GscPulse\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\GscPulse\Filament\Pages\GscConnectPage;
use Modules\GscPulse\Models\GscCredential;
use Modules\GscPulse\Services\GscFetcher;
use Modules\GscPulse\Support\OAuthClient;
use Throwable;

/**
 * Google OAuth 回调（GET admin/gsc-pulse/callback）：
 * 校验 state → 校验站点属性格式 → code 换 token → 校验授权范围 → 校验属性可访问 → 凭据落库 → 回连接页。
 * 全程手写 Http 客户端，无第三方 SDK。错误统一回跳连接页并 flash 提示。
 *
 * WHY 换完 token 还要再校验：只拿到 token 不代表可用。授权范围不含 webmasters.readonly，
 * 或被授权的 Google 账号对该站点属性没有访问权时，连接会显示成功、但之后每次拉取都是 400/403，
 * 典型症状是「连上了却一条数据都没有」。所以先验范围、再验属性，验不过就绝不落库
 * （连旧的有效凭据也不覆盖），并把原因明确回传给管理员。
 *
 * WHY 每个出口都记录 OAuthClient::recordAttempt：flash 提示是一次性的，管理员没看到就永久丢失，
 * 而共享主机用户看不了 laravel.log。持久化后连接页常驻显示最近一次授权的结果与原因，
 * 「静默失败」从此可追溯。
 */
class GscOAuthCallbackController extends Controller
{
    /** Search Console 站点属性列表接口（用于确认被授权账号确有这个属性） */
    private const SITES_URL = 'https://searchconsole.googleapis.com/webmasters/v3/sites';

    /** 令牌自省接口：令牌响应里没带 scope 时用它补查实际授予的范围 */
    private const TOKENINFO_URL = 'https://oauth2.googleapis.com/tokeninfo';

    public function __invoke(Request $request): RedirectResponse
    {
        $back = GscConnectPage::getUrl();

        // 0. 插件开关关闭：页面已显示「未启用」，这里直接拒掉，不做任何换令牌动作
        if (! config('gsc-pulse.enabled')) {
            return $this->fail($back, 'GSC 数据回流功能当前未启用，未写入任何凭据。');
        }

        // 1. 用户在 Google 侧拒绝授权
        if ($request->filled('error')) {
            return $this->fail($back, 'Google 授权被取消或拒绝（'.$request->query('error').'），未写入任何凭据。');
        }

        // 2. state 防 CSRF：必须与连接页存入 session 的值一致（一次性，取出即焚）
        $state = (string) $request->query('state', '');
        $expected = (string) session()->pull('gsc-pulse.state', '');

        if ($state === '' || $expected === '' || ! hash_equals($expected, $state)) {
            return $this->fail($back, '授权状态校验失败（state 不匹配），请回到「GSC 连接」页重新点击「连接 Google」。');
        }

        // 3. 授权码必须存在
        $code = (string) $request->query('code', '');

        if ($code === '') {
            return $this->fail($back, '回调缺少授权码（code），请重新发起连接。');
        }

        // 4. 站点属性格式校验：空值/相对路径会让后面的 GSC 请求路径变成无效的 '//'
        $siteUrl = GscCredential::defaultSiteUrl();

        if (! GscCredential::isValidSiteUrl($siteUrl)) {
            return $this->fail($back, '后台配置的 GSC 站点属性（site_url）不是合法的 http/https 绝对地址或 sc-domain: 域名资源，请到「GSC 连接」页修正后再重新授权。');
        }

        // 5. code 换 token（OAuth2 标准端点，表单提交）。
        //    兜底 Throwable：连不上 Google（超时/DNS）时 Http 会抛连接异常，
        //    不接住就是 500 白屏——管理员既看不到 flash 也无从排查。
        try {
            $response = Http::asForm()->post(GscFetcher::TOKEN_URL, [
                'code' => $code,
                'client_id' => OAuthClient::clientId(),
                'client_secret' => OAuthClient::clientSecret(),
                'redirect_uri' => route('gsc-pulse.callback'),
                'grant_type' => 'authorization_code',
            ]);
        } catch (Throwable $e) {
            return $this->fail($back, '服务器连接 Google 失败（'.$this->shortReason($e->getMessage()).'）。请稍后重试；若反复出现请检查服务器出站网络。');
        }

        $accessToken = (string) $response->json('access_token', '');

        if ($response->failed() || $accessToken === '') {
            $detail = $this->redact((string) ($response->json('error_description')
                ?? $response->json('error.message')
                ?? $response->body()), $accessToken);
            $detail = mb_substr(trim($detail), 0, 160);

            return $this->fail($back, '换取令牌失败：'.$detail.'。常见原因：Client Secret 不对，或 Google 控制台里登记的重定向 URI 与连接页显示的不完全一致（redirect_uri_mismatch）。');
        }

        // 6. 校验实际授予的授权范围：必须包含只读范围，否则后续拉取一律 403
        $requiredScope = (string) config('gsc-pulse.scope', 'https://www.googleapis.com/auth/webmasters.readonly');
        $grantedScope = (string) $response->json('scope', '');

        if ($grantedScope === '') {
            // 令牌响应没带 scope：用 tokeninfo 补查一次，避免把「没返回」误判成「没授权」
            $grantedScope = $this->fetchGrantedScope($accessToken);
        }

        if (! $this->scopeContains($grantedScope, $requiredScope)) {
            return $this->fail($back, '本次授权没有包含 Search Console 只读权限（'.$requiredScope.'），未写入任何凭据。请重新点击「连接 Google」并在 Google 授权页勾选该权限。');
        }

        // 7. 校验被授权账号确实能访问这个站点属性：否则会出现「连接成功但一条数据都没有」
        try {
            $properties = $this->fetchSiteProperties($accessToken);
        } catch (Throwable $e) {
            return $this->fail($back, '读取 Search Console 站点列表时服务器请求失败（'.$this->shortReason($e->getMessage()).'），未写入任何凭据，请重试。');
        }

        if ($properties['error'] !== null) {
            return $this->fail($back, '读取 Search Console 站点列表失败（'.$properties['error'].'），未写入任何凭据。请确认 Search Console API 已启用、且刚授权的账号确实拥有该资源，再重新授权。');
        }

        if (! $this->hasProperty($properties['entries'], $siteUrl)) {
            $list = implode('、', array_slice(array_map(
                fn (array $item): string => (string) ($item['siteUrl'] ?? ''),
                $properties['entries']
            ), 0, 5));

            return $this->fail($back, '刚授权的 Google 账号没有站点属性「'.$siteUrl.'」的访问权限，未写入任何凭据。该账号当前可见的属性：'.($list !== '' ? $list : '（无）').'。请换用有权限的账号，或把「GSC 连接」页的站点属性改成上面某一个（域名资源形如 sc-domain:example.com）。');
        }

        // 8. 校验全部通过才落库（每站点属性一行，重复授权覆盖旧令牌）。
        //    refresh_token 仅在 prompt=consent 时下发；若本次未返回则保留旧值。
        $existing = GscCredential::current($siteUrl);
        $refreshToken = (string) $response->json('refresh_token', $existing?->refresh_token ?? '');

        GscCredential::updateOrCreate(
            ['site_url' => $siteUrl],
            [
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'token_expires_at' => now()->addSeconds(max(60, (int) $response->json('expires_in', 3600))),
            ],
        );

        OAuthClient::recordAttempt(true, '连接成功（属性 '.$siteUrl.'），凭据已保存。');

        return redirect($back)->with('gsc_connected', true);
    }

    /**
     * 统一失败出口：flash 给当次访问 + 持久化给连接页常驻展示 + 落一条 warning 日志。
     */
    private function fail(string $back, string $message): RedirectResponse
    {
        OAuthClient::recordAttempt(false, $message);
        Log::warning('[GscPulse] OAuth 授权失败：'.$message);

        return redirect($back)->with('gsc_error', $message);
    }

    /**
     * 异常信息截短：连接异常的 message 可能带整段堆栈上下文，只留开头即可定位。
     */
    private function shortReason(string $message): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $message) ?? $message);

        return mb_substr($message, 0, 80);
    }

    /**
     * 拉取当前 access_token 的站点属性列表。
     *
     * @return array{entries: list<array<string, mixed>>, error: string|null} error 非 null 表示读取失败
     */
    protected function fetchSiteProperties(string $accessToken): array
    {
        $response = Http::withToken($accessToken)->get(self::SITES_URL);

        if ($response->failed()) {
            $detail = $this->redact((string) ($response->json('error.message')
                ?? $response->json('error_description')
                ?? $response->body()), $accessToken);

            return ['entries' => [], 'error' => mb_substr(trim($detail), 0, 160)];
        }

        $entries = $response->json('siteEntry');

        return [
            'entries' => is_array($entries) ? array_values($entries) : [],
            'error' => null,
        ];
    }

    /**
     * 令牌自省：换取实际授予的 scope 串（令牌响应未带 scope 时的兜底）。
     */
    protected function fetchGrantedScope(string $accessToken): string
    {
        $response = Http::get(self::TOKENINFO_URL, [
            'access_token' => $accessToken,
        ]);

        if ($response->failed()) {
            return '';
        }

        return (string) ($response->json('scope') ?? '');
    }

    /**
     * 授予范围是否包含目标范围（scope 串为空格分隔的多个范围）。
     */
    protected function scopeContains(string $grantedScope, string $requiredScope): bool
    {
        $granted = preg_split('/\s+/', trim($grantedScope)) ?: [];

        return in_array($requiredScope, $granted, true);
    }

    /**
     * 站点属性列表里是否包含目标属性（尾斜杠与大小写差异视为同一个属性）。
     *
     * @param  list<array<string, mixed>>  $properties
     */
    protected function hasProperty(array $properties, string $siteUrl): bool
    {
        $target = $this->normalizeSiteKey($siteUrl);

        foreach ($properties as $item) {
            if ($this->normalizeSiteKey((string) ($item['siteUrl'] ?? '')) === $target) {
                return true;
            }
        }

        return false;
    }

    /**
     * 站点属性比较键：去掉尾斜杠并转小写（GSC 的站点 URL 大小写不敏感）。
     */
    protected function normalizeSiteKey(string $siteUrl): string
    {
        return mb_strtolower(rtrim(trim($siteUrl), '/'));
    }

    /**
     * 抹掉可能出现在错误信息里的令牌片段：报错文案绝不外泄 access_token。
     */
    protected function redact(string $message, string ...$secrets): string
    {
        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $message = str_replace($secret, '***', $message);
            }
        }

        return $message;
    }
}
