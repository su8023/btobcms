<?php

namespace App\Services\Ai;

use App\Models\AiCall;
use App\Support\SafeUrl;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class LlmManager
{
    /**
     * 通过 OpenAI 兼容协议调用大模型（DeepSeek / GLM / Kimi / OpenAI 等）。
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     * @param  string  $tier  能力档位，见 config/ai.php tiers
     *
     * @throws RuntimeException 未配置或调用失败
     */
    public function chat(
        array $messages,
        string $purpose = 'chat',
        string $tier = 'chat',
        float $temperature = 0.7,
    ): string {
        $providerName = config("ai.tiers.{$tier}", config('ai.tiers.chat'));
        $provider = config("ai.providers.{$providerName}");

        if (! $provider || blank($provider['key'] ?? null)) {
            // 抛错文案面向运营小白：所有 AI 功能（写作室/填充/翻译/FAQ/自动 SEO）共用此处
            throw new RuntimeException(
                'AI 服务还没配置好：请联系管理员，在「插件中心 → AI 内容写作 → 设置」里填入 AI 接口密钥并保存。'
            );
        }

        $response = $this->send($provider, $messages, $temperature, $providerName);

        $ok = $response->successful();
        $json = (array) $response->json();

        AiCall::create([
            'provider' => $providerName,
            'model' => $provider['model'],
            'purpose' => $purpose,
            'prompt_tokens' => data_get($json, 'usage.prompt_tokens', 0),
            'completion_tokens' => data_get($json, 'usage.completion_tokens', 0),
            'status' => $ok ? 'ok' : 'failed',
            'error' => $ok ? null : $this->redact((string) $response->body()),
        ]);

        if (app()->environment('local', 'testing')) {
            logger()->info('llm resp', [
                'status' => $response->status(),
                'body' => mb_substr((string) $response->body(), 0, 60),
                'url_used' => rtrim((string) $provider['base_url'], '/').'/chat/completions',
                'model' => $provider['model'],
            ]);
        }

        if (! $ok) {
            throw new RuntimeException(
                "LLM 调用失败 [{$providerName}]：".mb_substr($this->redact((string) $response->body()), 0, 300)
            );
        }

        return (string) data_get($json, 'choices.0.message.content', '');
    }

    /**
     * 发起请求。
     *
     * 两道防线：
     *   1. base_url 必须是 http/https 且不得指向回环/链路本地（云元数据）等保留地址，
     *      否则 API Key 会被送到攻击者主机，或让服务端去打内网端点；
     *   2. 显式给出 max_tokens，避免模型一路生成到供应商输出上限（成本不可控）。
     */
    protected function send(array $provider, array $messages, float $temperature, string $providerName): Response
    {
        $baseUrl = rtrim((string) ($provider['base_url'] ?? ''), '/');

        $reason = SafeUrl::reason(
            $baseUrl,
            (bool) config('ai.allow_private_hosts', false)
        );

        if ($reason !== null) {
            // 提示里不要回显完整地址（可能含内网拓扑信息）
            throw new RuntimeException(
                "AI 接口地址不可用（{$providerName}）：{$reason}。"
                .'若确为自建内网模型，请在 .env 设置 AI_ALLOW_PRIVATE_HOSTS=true 后重试。'
            );
        }

        $payload = [
            'model' => $provider['model'],
            'messages' => $messages,
            'temperature' => $temperature,
        ];

        $maxTokens = (int) config('ai.max_tokens', 0);

        if ($maxTokens > 0) {
            $payload['max_tokens'] = $maxTokens;
        }

        return Http::withToken($provider['key'])
            ->timeout(config('ai.timeout', 120))
            ->retry((int) config('ai.retries', 2), 2000, throw: false)
            ->post($baseUrl.'/chat/completions', $payload);
    }

    /**
     * 脱敏上游返回体：抹掉可能出现在报错里的密钥片段。
     *
     * 原始报错会被存进 ai_calls 表并回显到后台界面，
     * 上游/代理的细节（含 URL、请求头片段）不该原样外泄。
     */
    protected function redact(string $body): string
    {
        $redacted = preg_replace('/(sk-[A-Za-z0-9_\-]{8})[A-Za-z0-9_\-]+/', '$1***', $body) ?? $body;
        $redacted = preg_replace('/(GOCSPX-[A-Za-z0-9_\-]{4})[A-Za-z0-9_\-]+/', '$1***', $redacted) ?? $redacted;
        $redacted = preg_replace('/("?(?:api[_-]?key|authorization|token)"?\s*[:=]\s*"?)[^"\s,}]{6,}/i', '$1***', $redacted) ?? $redacted;

        return $redacted;
    }
}
