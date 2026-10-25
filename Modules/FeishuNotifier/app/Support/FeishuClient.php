<?php

namespace Modules\FeishuNotifier\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class FeishuClient
{
    /**
     * 发送飞书群消息卡片。未配置 Webhook 时优雅跳过（skipped）；
     * 网络失败返回 failed（含错误信息），绝不抛异常影响主流程。
     *
     * @return array{status: string, error: ?string} status: sent|skipped|failed
     */
    public static function sendCard(
        string $title,
        string $markdown,
        string $color = 'blue',
        ?string $buttonUrl = null,
        ?string $buttonText = null,
    ): array {
        // 后台插件中心保存的设置优先，其次 .env/config
        $webhook = (string) plugin_setting('feishu-notifier', 'webhook', '');

        if (! plugin_setting('feishu-notifier', 'enabled', true) || $webhook === '') {
            Log::info('[feishu-notifier] 未配置 Webhook，跳过推送', ['title' => $title]);

            return ['status' => 'skipped', 'error' => null];
        }

        $elements = [
            ['tag' => 'div', 'text' => ['tag' => 'lark_md', 'content' => $markdown]],
        ];

        if ($buttonUrl) {
            $elements[] = ['tag' => 'action', 'actions' => [[
                'tag' => 'button',
                'text' => ['tag' => 'plain_text', 'content' => $buttonText ?? '查看详情'],
                'type' => 'primary',
                'url' => $buttonUrl,
            ]]];
        }

        $payload = [
            'msg_type' => 'interactive',
            'card' => [
                'config' => ['wide_screen_mode' => true],
                'header' => [
                    'template' => $color,
                    'title' => ['tag' => 'plain_text', 'content' => $title],
                ],
                'elements' => $elements,
            ],
        ];

        if ($secret = plugin_setting('feishu-notifier', 'secret')) {
            $timestamp = (string) now()->getTimestamp();
            $payload['timestamp'] = $timestamp;
            $payload['sign'] = base64_encode(hash_hmac('sha256', '', $timestamp."\n".$secret, true));
        }

        try {
            $response = Http::timeout(8)->post($webhook, $payload);
            $json = (array) $response->json();

            if (! $response->successful() || (int) ($json['code'] ?? 0) !== 0) {
                $error = 'HTTP '.$response->status().' '.mb_substr($response->body(), 0, 200);
                Log::warning('[feishu-notifier] 推送失败', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 300)]);

                return ['status' => 'failed', 'error' => $error];
            }
        } catch (Throwable $e) {
            Log::warning('[feishu-notifier] 推送异常', ['error' => $e->getMessage()]);

            return ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 200)];
        }

        return ['status' => 'sent', 'error' => null];
    }
}
