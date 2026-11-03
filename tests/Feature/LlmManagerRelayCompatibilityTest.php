<?php

namespace Tests\Feature;

use App\Services\Ai\LlmManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\AiContentWriter\Services\ArticleStudio;
use ReflectionMethod;
use Tests\TestCase;

/**
 * LlmManager 对中转站非标准响应的兼容。
 *
 * 部分中转/代理会在非流式 JSON 响应尾部追加流式终止符 `data: [DONE]`
 * （正规 OpenAI 兼容 API 只在 SSE 流里用它）。严格 json_decode 因尾部
 * 垃圾返回 null → content 取不到 → 用户只看到「AI 返回无法解析」。
 * 线上实锤：切到自建中转后 plan/compose/translate 全部解析失败。
 */
class LlmManagerRelayCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /**
     * 直接测 decodeBody（protected → 反射）。
     *
     * @param  string  $body  上游原始响应体
     * @param  string|null  $expectContent  期望取到的 content（null = 期望解析失败）
     */
    private function decode(string $body, ?string $expectContent): void
    {
        $manager = new LlmManager;
        $method = new ReflectionMethod($manager, 'decodeBody');
        $method->setAccessible(true);

        $json = $method->invoke($manager, $body);

        if ($expectContent === null) {
            $this->assertSame([], $json, '解析失败应返回空数组');

            return;
        }

        $this->assertSame($expectContent, data_get($json, 'choices.0.message.content'));
    }

    public function test_strict_json_passes_through(): void
    {
        $body = '{"choices":[{"finish_reason":"stop","message":{"content":"OK"}}],"usage":{"total_tokens":9}}';

        $this->decode($body, 'OK');
    }

    public function test_trailing_done_glued_to_json_is_stripped(): void
    {
        // 中转站实测形态：DONE 直接贴在收尾 } 后（同一行）
        $body = '{"choices":[{"message":{"content":"OK"}}],"usage":{}}data: [DONE]\n\n';

        $this->decode($body, 'OK');
    }

    public function test_trailing_done_on_own_line_is_stripped(): void
    {
        $body = "{\"choices\":[{\"message\":{\"content\":\"OK\"}}]}\ndata: [DONE]\n\n";

        $this->decode($body, 'OK');
    }

    public function test_trailing_done_case_insensitive(): void
    {
        $body = '{"choices":[{"message":{"content":"OK"}}]}DATA: [DONE]';

        $this->decode($body, 'OK');
    }

    public function test_non_json_garbage_still_fails(): void
    {
        $this->decode('upstream exploded', null);
    }

    /**
     * 形态 2：中转无视 stream=false，直接吐 SSE 流——需按 delta 重组。
     * 线上实测 mm/MiniMax-M3.1-Flash 通道就是这种形态。
     */
    public function test_sse_stream_is_reassembled(): void
    {
        $sse = "data: {\"choices\":[{\"delta\":{\"role\":\"assistant\",\"content\":\"\"}}]}\n\n"
            ."data: {\"choices\":[{\"delta\":{\"content\":\"<h2>\"}}]}\n\n"
            ."data: {\"choices\":[{\"delta\":{\"content\":\"title</h2>\"}}]}\n\n"
            ."data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"stop\"}],\"usage\":{\"prompt_tokens\":10,\"completion_tokens\":5}}\n\n"
            ."data: [DONE]\n\n";

        $this->decode($sse, '<h2>title</h2>');
    }

    public function test_sse_usage_is_captured_for_call_logging(): void
    {
        $sse = "data: {\"choices\":[{\"delta\":{\"content\":\"OK\"}}]}\n\n"
            ."data: {\"choices\":[{\"delta\":{},\"finish_reason\":\"stop\"}],\"usage\":{\"prompt_tokens\":2955,\"completion_tokens\":12,\"total_tokens\":2967}}\n\n"
            ."data: [DONE]\n";
        $manager = new LlmManager;
        $method = new ReflectionMethod($manager, 'decodeBody');
        $method->setAccessible(true);

        $json = $method->invoke($manager, $sse);

        $this->assertSame(2955, data_get($json, 'usage.prompt_tokens'));
        $this->assertSame('stop', data_get($json, 'choices.0.finish_reason'));
    }

    public function test_end_to_end_plan_with_done_suffix_succeeds(): void
    {
        config([
            'ai.providers.deepseek.key' => 'sk-test-fake',
            'ai.providers.deepseek.base_url' => 'https://api.deepseek.test/v1',
            'ai.providers.deepseek.model' => 'deepseek-chat',
        ]);

        $plan = json_encode([
            'title' => 'How to Choose a Ball Valve Supplier',
            'angle' => 'Procurement risk',
            'outline' => [
                ['h2' => 'Material', 'points' => ['316 vs 304']],
            ],
            'keywords' => ['ball valve supplier'],
            'faq' => [['q' => 'MOQ?']],
        ], JSON_UNESCAPED_UNICODE);

        $body = json_encode([
            'choices' => [['message' => ['content' => $plan]]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 200],
        ], JSON_UNESCAPED_SLASHES).'data: [DONE]';

        Http::fake([
            'api.deepseek.test/*' => Http::response($body, 200),
        ]);

        $studio = app(ArticleStudio::class);
        $result = $studio->plan('ball valve supplier', '', 'en');

        $this->assertSame('How to Choose a Ball Valve Supplier', $result['title']);
        $this->assertCount(1, $result['outline']);
    }
}
