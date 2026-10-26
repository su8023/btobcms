<?php

namespace App\Services\Ai;

use App\Models\AiCall;
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
            throw new RuntimeException(
                "AI 服务未配置：请在 .env 中为 provider [{$providerName}] 设置 API Key（参见 config/ai.php）。"
            );
        }

        $response = Http::withToken($provider['key'])
            ->timeout(config('ai.timeout', 120))
            ->retry((int) config('ai.retries', 2), 2000, throw: false)
            ->post(rtrim($provider['base_url'], '/').'/chat/completions', [
                'model' => $provider['model'],
                'messages' => $messages,
                'temperature' => $temperature,
            ]);

        $ok = $response->successful();
        $json = (array) $response->json();

        AiCall::create([
            'provider' => $providerName,
            'model' => $provider['model'],
            'purpose' => $purpose,
            'prompt_tokens' => data_get($json, 'usage.prompt_tokens', 0),
            'completion_tokens' => data_get($json, 'usage.completion_tokens', 0),
            'status' => $ok ? 'ok' : 'failed',
            'error' => $ok ? null : mb_substr($response->body(), 0, 2000),
        ]);

        if (app()->environment("testing")) { logger()->info("llm resp", ["status" => $response->status(), "body" => mb_substr($response->body(), 0, 60), "url_used" => rtrim($provider["base_url"], "/")."/chat/completions", "model" => $provider["model"]]); }
        if (! $ok) {
            throw new RuntimeException(
                "LLM 调用失败 [{$providerName}]：".mb_substr($response->body(), 0, 300)
            );
        }

        return (string) data_get($json, 'choices.0.message.content', '');
    }
}
