<?php

namespace Modules\GscPulse\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Modules\GscPulse\Filament\Pages\GscConnectPage;
use Modules\GscPulse\Models\GscCredential;
use Modules\GscPulse\Services\GscFetcher;

/**
 * Google OAuth 回调（GET admin/gsc-pulse/callback）：
 * 校验 state → code 换 token → 凭据落库 → 回连接页。
 * 全程手写 Http 客户端，无第三方 SDK。错误统一回跳连接页并 flash 提示。
 */
class GscOAuthCallbackController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $back = GscConnectPage::getUrl();

        // 1. 用户在 Google 侧拒绝授权
        if ($request->filled('error')) {
            return redirect($back)->with('gsc_error', 'Google 授权被取消或拒绝（'.$request->query('error').'），未写入任何凭据。');
        }

        // 2. state 防 CSRF：必须与连接页存入 session 的值一致（一次性，取出即焚）
        $state = (string) $request->query('state', '');
        $expected = (string) session()->pull('gsc-pulse.state', '');

        if ($state === '' || $expected === '' || ! hash_equals($expected, $state)) {
            return redirect($back)->with('gsc_error', '授权状态校验失败（state 不匹配），请回到「GSC 连接」页重新点击「连接 Google」。');
        }

        // 3. 授权码必须存在
        $code = (string) $request->query('code', '');

        if ($code === '') {
            return redirect($back)->with('gsc_error', '回调缺少授权码（code），请重新发起连接。');
        }

        // 4. code 换 token（OAuth2 标准端点，表单提交）
        $response = Http::asForm()->post(GscFetcher::TOKEN_URL, [
            'code' => $code,
            'client_id' => (string) plugin_setting('gsc-pulse', 'client_id', ''),
            'client_secret' => (string) plugin_setting('gsc-pulse', 'client_secret', ''),
            'redirect_uri' => route('gsc-pulse.callback'),
            'grant_type' => 'authorization_code',
        ]);

        $accessToken = (string) $response->json('access_token', '');

        if ($response->failed() || $accessToken === '') {
            $detail = (string) ($response->json('error_description')
                ?? $response->json('error.message')
                ?? $response->body());
            $detail = mb_substr(trim($detail), 0, 160);

            return redirect($back)->with('gsc_error', '换取令牌失败：'.$detail);
        }

        // 5. 凭据落库（每站点属性一行，重复授权覆盖旧令牌）。
        //    refresh_token 仅在 prompt=consent 时下发；若本次未返回则保留旧值。
        $siteUrl = GscCredential::defaultSiteUrl();
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

        return redirect($back)->with('gsc_connected', true);
    }
}
