<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\AiContentWriter\Services\ArticleStudio;
use Tests\TestCase;

/**
 * AI 写作室两段式流程：plan（出方案）→ write（确认成文）。
 * LLM 底座契约测试（OpenAI 兼容协议 mock，不实际调外部 API）。
 */
class AiStudioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(User::first());

        // LlmManager 在发请求前校验 .env 的 Key——测试注入假 Key（HTTP 层已被 Http::fake 拦截）
        config([
            'ai.providers.deepseek.key' => 'sk-test-fake',
            'ai.providers.deepseek.base_url' => 'https://api.deepseek.test/v1',
            'ai.providers.deepseek.model' => 'deepseek-chat',
        ]);
    }

    /**
     * OpenAI 响应体（单层结构——外层数组会导致 LlmManager 取不到 content）。
     */
    private function openAiBody(string $content): array
    {
        return [
            'choices' => [
                ['message' => ['content' => $content]],
            ],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 200],
        ];
    }

    private function planContent(): string
    {
        return json_encode([
            'title' => 'How to Choose Stainless Steel Valves',
            'angle' => 'Procurement risk视角',
            'outline' => [
                ['h2' => 'Material grades', 'points' => ['316 vs 304', 'Certification']],
                ['h2' => 'Quality control', 'points' => ['AQL 2.5', 'Pressure test']],
            ],
            'keywords' => ['stainless steel valve supplier', 'manufacturer from China'],
            'faq' => [['q' => 'MOQ?'], ['q' => 'Lead time?']],
        ], JSON_UNESCAPED_UNICODE);
    }

    private function articleContent(): string
    {
        return json_encode([
            'slug' => 'stainless-steel-valves',
            'excerpt' => 'Guide for buyers.',
            'html' => '<h2>Material grades</h2><p>316 is better.</p>',
            'faq' => [['q' => 'MOQ?', 'a' => '50 pcs.']],
        ], JSON_UNESCAPED_UNICODE);
    }

    public function test_studio_page_renders(): void
    {
        $this->get('/admin/ai-studio')
            ->assertOk()
            ->assertSee(__('admin.ai_studio.step1'))
            ->assertSee(__('admin.ai_studio.make_plan'));
    }

    public function test_plan_endpoint_returns_structured_plan(): void
    {
        Http::fake(['*' => Http::response($this->openAiBody($this->planContent()), 200)]);

        $this->postJson('/admin/ai-studio/plan', [
            'topic' => 'How to choose stainless steel valves',
            'lang' => 'en',
        ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('plan.title', 'How to Choose Stainless Steel Valves')
            ->assertJsonCount(2, 'plan.outline');
    }

    public function test_plan_requires_topic(): void
    {
        $this->postJson('/admin/ai-studio/plan', [])->assertStatus(422);
    }

    public function test_write_creates_draft_post_from_plan(): void
    {
        Http::fake(['*' => Http::response($this->openAiBody($this->articleContent()), 200)]);

        $plan = [
            'title' => 'How to Choose Stainless Steel Valves',
            'outline' => [
                ['h2' => 'Material grades', 'points' => ['316 vs 304']],
            ],
            'keywords' => ['valve supplier'],
            'faq' => [['q' => 'MOQ?']],
        ];

        $this->postJson('/admin/ai-studio/write', ['plan' => $plan, 'lang' => 'en'])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $post = Post::where('slug', 'stainless-steel-valves')->first();
        $this->assertNotNull($post, '草稿应入库');
        $this->assertSame(Post::STATUS_DRAFT, $post->status, '必须是草稿态（待人工审）');
        $this->assertTrue($post->is_ai_generated);
        $this->assertSame('How to Choose Stainless Steel Valves', $post->getTranslation('title', 'en'));

        // 方案阶段的关键词应直接落到 seo_meta
        $meta = \App\Models\SeoMeta::where('metable_type', Post::class)->where('metable_id', $post->id)->first();
        $this->assertNotNull($meta, 'AI 写作室草稿应预置 seo_meta.keywords');
        $this->assertSame('valve supplier', $meta->getTranslation('keywords', 'en'));
    }

    public function test_write_requires_title_and_outline(): void
    {
        $this->postJson('/admin/ai-studio/write', ['plan' => ['title' => '']])
            ->assertStatus(422);
    }

    public function test_custom_model_settings_override_provider(): void
    {
        Setting::put('plugin.AiContentWriter', [
            'custom_base_url' => 'https://api.example.com/v1',
            'custom_api_key' => 'sk-test-123',
            'custom_model' => 'my-model',
        ]);

        Http::fake(['https://api.example.com/*' => Http::response($this->openAiBody($this->planContent()), 200)]);

        $studio = app(ArticleStudio::class);
        $plan = $studio->plan('Test topic');

        $this->assertSame('How to Choose Stainless Steel Valves', $plan['title']);
        Http::assertSent(fn ($req) => str_starts_with($req->url(), 'https://api.example.com/v1'));
    }

    public function test_gpt_style_provider_via_settings(): void
    {
        // 真实场景：后台填 OpenAI 兼容的任意供应商
        Setting::put('plugin.AiContentWriter', [
            'custom_base_url' => 'https://api.gpt-proxy.example/v1',
            'custom_api_key' => 'sk-x',
            'custom_model' => 'gpt-4o-mini',
        ]);

        Http::fake(['*' => Http::response($this->openAiBody($this->planContent()), 200)]);

        $studio = app(ArticleStudio::class);
        $studio->plan('Another topic');

        Http::assertSent(function ($req) {
            return str_contains($req->url(), 'gpt-proxy.example')
                && $req['model'] === 'gpt-4o-mini'
                && $req->header('Authorization')[0] === 'Bearer sk-x';
        });
    }

    public function test_studio_page_hides_for_guests(): void
    {
        auth()->logout();

        $this->get('/admin/ai-studio')->assertRedirect('/admin/login');
        $this->postJson('/admin/ai-studio/plan', ['topic' => 'x'])->assertUnauthorized();
    }
}
