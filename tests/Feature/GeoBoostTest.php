<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\GeoBoost\Models\AiVisit;
use Modules\GeoBoost\Models\GeoFaq;
use Modules\GeoBoost\Services\FaqGenerator;
use Tests\TestCase;

/**
 * GeoBoost：FAQ 生成/前台渲染/JSON-LD、AI 引擎来访追踪。
 */
class GeoBoostTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(User::first());

        config([
            'ai.providers.deepseek.key' => 'sk-test-fake',
            'ai.providers.deepseek.base_url' => 'https://api.deepseek.test/v1',
        ]);
    }

    private function makePost(): Post
    {
        return Post::create([
            'type' => 'post',
            'slug' => 'geo-'.uniqid(),
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
            'title' => ['en' => 'Gate Valve Guide'],
            'content' => ['en' => '<h2>Material</h2><p>'.str_repeat('Details about valve materials and quality. ', 30).'</p>'],
        ]);
    }

    private function faqBody(): array
    {
        return ['choices' => [['message' => ['content' => json_encode([
            'faqs' => [
                ['q' => 'What is the MOQ?', 'a' => 'MOQ is flexible, usually 100 pcs.'],
                ['q' => 'Lead time?', 'a' => '25-35 days after deposit.'],
            ],
        ], JSON_UNESCAPED_UNICODE)]]]];
    }

    public function test_faq_generator_stores_ai_faqs(): void
    {
        Http::fake(['*' => Http::response($this->faqBody(), 200)]);

        $post = $this->makePost();
        $count = app(FaqGenerator::class)->generateFor($post, 'en');

        $this->assertSame(2, $count);
        $this->assertSame(2, GeoFaq::where('faqable_id', $post->id)->where('locale', 'en')->count());
        $this->assertSame('ai', GeoFaq::first()->source);
    }

    public function test_regenerate_replaces_ai_faqs_but_keeps_manual(): void
    {
        $post = $this->makePost();
        GeoFaq::create([
            'faqable_type' => Post::class, 'faqable_id' => $post->id,
            'locale' => 'en', 'sort' => 9, 'question' => '手工问题', 'answer' => '手工答案', 'source' => 'manual',
        ]);

        Http::fake(['*' => Http::response($this->faqBody(), 200)]);
        app(FaqGenerator::class)->generateFor($post, 'en');

        // 手工的还在，AI 的共 3 条（1 手工 + 2 新生成）
        $this->assertSame(3, GeoFaq::where('faqable_id', $post->id)->count());
        $this->assertTrue(GeoFaq::where('question', '手工问题')->exists());
    }

    public function test_throws_when_locale_has_no_content(): void
    {
        $post = $this->makePost();

        $this->expectException(\RuntimeException::class);
        app(FaqGenerator::class)->generateFor($post, 'de');
    }

    public function test_faq_block_and_json_ld_render_on_post_page(): void
    {
        $post = $this->makePost();
        GeoFaq::create([
            'faqable_type' => Post::class, 'faqable_id' => $post->id,
            'locale' => 'en', 'sort' => 0, 'question' => 'What is the MOQ?', 'answer' => '100 pcs.', 'source' => 'ai',
        ]);

        $this->get('/blog/'.$post->slug)
            ->assertOk()
            ->assertSee('What is the MOQ?', false)
            ->assertSee('100 pcs.', false)
            ->assertSee('"@type":"FAQPage"', false);
    }

    public function test_faq_block_hidden_without_faqs(): void
    {
        $post = $this->makePost();

        $this->get('/blog/'.$post->slug)
            ->assertOk()
            ->assertDontSee('FAQPage');
    }

    public function test_ai_referrer_is_tracked(): void
    {
        $this->get('/', ['referer' => 'https://chatgpt.com/some-thread']);

        $this->assertSame(1, AiVisit::count());
        $visit = AiVisit::first();
        $this->assertSame('ChatGPT', $visit->engine);
        $this->assertSame('/', $visit->path); // 首页 path 为 '/'
    }

    public function test_normal_referrer_is_not_tracked(): void
    {
        $this->get('/', ['referer' => 'https://www.google.com/search?q=valve']);
        $this->get('/', ['referer' => 'https://example.com/page']);

        $this->assertSame(0, AiVisit::count());
    }

    public function test_self_referrer_is_not_tracked(): void
    {
        config(['app.url' => 'http://localhost']);

        $this->get('/', ['referer' => 'http://localhost/blog']);

        $this->assertSame(0, AiVisit::count());
    }

    public function test_perplexity_referrer_tracked_with_landing_path(): void
    {
        $post = $this->makePost();

        $this->get('/blog/'.$post->slug, ['referer' => 'https://www.perplexity.ai/search/valve-suppliers']);

        $visit = AiVisit::first();
        $this->assertNotNull($visit);
        $this->assertSame('Perplexity', $visit->engine);
        $this->assertSame('blog/'.$post->slug, $visit->path);
    }

    public function test_ai_traffic_dashboard_renders(): void
    {
        AiVisit::create(['engine' => 'ChatGPT', 'path' => 'blog/x', 'created_at' => now()]);

        $this->get('/admin/ai-traffic')
            ->assertOk()
            ->assertSee('ChatGPT');
    }
}
