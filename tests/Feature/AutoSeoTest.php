<?php

namespace Tests\Feature;

use App\Events\PostPublished;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AutoSeo 全自动链路：发布 → TDK 生成 → IndexNow 推送。
 */
class AutoSeoTest extends TestCase
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
            'ai.providers.deepseek.model' => 'deepseek-chat',
            'autoseo.key_file' => storage_path('app/indexnow-test.key'),
        ]);
    }

    protected function tearDown(): void
    {
        @unlink(storage_path('app/indexnow-test.key'));
        parent::tearDown();
    }

    public function test_indexnow_key_file_served_at_txt_route(): void
    {
        // IndexNow 协议标准验证路径是 /{key}.txt（带 .txt 后缀）
        $key = \Modules\AutoSeo\Support\IndexNow::key();
        $this->get("/{$key}.txt")->assertOk()->assertSee($key);

        // 非法 key 一律 404
        $this->get("/".str_repeat("a", 31).".txt")->assertNotFound();
        $this->get("/".str_repeat("a", 32))->assertNotFound();
    }

    public function test_indexnow_key_is_stable_and_hex32(): void
    {
        $k1 = \Modules\AutoSeo\Support\IndexNow::key();
        $k2 = \Modules\AutoSeo\Support\IndexNow::key();

        $this->assertSame($k1, $k2, '密钥必须持久化稳定');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $k1);
    }

    public function test_key_file_route_serves_key_and_404_otherwise(): void
    {
        $key = \Modules\AutoSeo\Support\IndexNow::key();

        $this->get('/'.$key)
            ->assertOk()
            ->assertSee($key);

        $this->get('/ffffffffffffffffffffffffffffffff')->assertNotFound();
        $this->get('/short')->assertNotFound();
    }

    public function test_post_publish_triggers_tdk_generation_and_indexnow_submit(): void
    {
        // fake 1：LLM 生成 TDK；fake 2：IndexNow API
        $tdkContent = json_encode([
            'seo_title' => 'Valve Sourcing Guide | Aples',
            'seo_description' => 'Complete guide to sourcing stainless steel valves from China with AQL quality control and OEM support.',
            'keywords' => 'valve supplier, stainless valve, China manufacturer',
        ], JSON_UNESCAPED_UNICODE);
        $tdkBody = [
            'choices' => [['message' => ['content' => $tdkContent]]],
            'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 60],
        ];

        Http::fake([
            'https://api.deepseek.test/*' => Http::response($tdkBody, 200),
            'https://api.indexnow.org/*' => Http::response(['ok' => true], 200),
        ]);

        $post = Post::create([
            'type' => 'post',
            'slug' => 'valve-guide-'.uniqid(),
            'status' => Post::STATUS_PUBLISHED,
            'title' => ['en' => 'Valve Sourcing Guide'],
            'content' => ['en' => '<p>How to source valves.</p>'],
            'published_at' => now(),
        ]);

        event(new PostPublished($post));

        // 队列 sync 执行后：TDK 已写入
        $meta = \App\Models\SeoMeta::where('metable_type', Post::class)->where('metable_id', $post->id)->first();
        $this->assertNotNull($meta, 'seo_meta 应已生成');
        $this->assertSame('Valve Sourcing Guide | Aples', $meta->getTranslation('title', 'en'));

        // IndexNow 收到 8 个语言变体 URL
        Http::assertSent(function ($req) {
            return str_contains($req->url(), 'indexnow')
                && count($req['urlList']) === count(site_locales())
                && str_contains($req['urlList'][0], '/blog/');
        });
    }

    public function test_indexnow_failure_does_not_break_publish_flow(): void
    {
        // LLM 失败 + IndexNow 网络失败：发布流程本身不能受影响
        Http::fake(['*' => Http::response([], 500)]);

        $post = Post::create([
            'type' => 'post',
            'slug' => 'fail-test-'.uniqid(),
            'status' => Post::STATUS_PUBLISHED,
            'title' => ['en' => 'Fail Test'],
            'content' => ['en' => '<p>x</p>'],
        ]);

        event(new PostPublished($post));

        $this->assertSame(Post::STATUS_PUBLISHED, $post->fresh()->status, '发布状态不受自动化失败影响');
    }

    public function test_ai_seo_generation_skipped_when_meta_complete(): void
    {
        // 标题+描述+关键词都齐的 TDK 不重复生成（省 token）；IndexNow 推送照常
        $post = Post::create([
            'type' => 'post',
            'slug' => 'has-meta-'.uniqid(),
            'status' => Post::STATUS_PUBLISHED,
            'title' => ['en' => 'Has Meta'],
            'content' => ['en' => '<p>x</p>'],
        ]);

        \App\Models\SeoMeta::create([
            'metable_type' => Post::class,
            'metable_id' => $post->id,
            'title' => ['en' => 'Existing SEO Title'],
            'description' => ['en' => 'Existing SEO description with enough text.'],
            'keywords' => ['en' => 'existing, keywords'],
        ]);

        Http::fake(['*' => Http::response([], 200)]);
        event(new PostPublished($post));

        Http::assertNotSent(fn ($req) => str_contains((string) $req->url(), 'deepseek'));
        Http::assertSent(fn ($req) => str_contains((string) $req->url(), 'indexnow'));
    }

    public function test_ai_seo_fills_missing_fields_only(): void
    {
        // 只有标题的 TDK：只补描述，不覆盖已有标题
        $tdkContent = json_encode([
            'seo_title' => 'Generated Title',
            'seo_description' => 'Generated description text long enough.',
        ], JSON_UNESCAPED_UNICODE);
        Http::fake([
            '*' => Http::response([
                'choices' => [['message' => ['content' => $tdkContent]]],
            ], 200),
        ]);

        $post = Post::create([
            'type' => 'post',
            'slug' => 'partial-meta-'.uniqid(),
            'status' => Post::STATUS_PUBLISHED,
            'title' => ['en' => 'Partial Meta'],
            'content' => ['en' => '<p>x</p>'],
        ]);

        \App\Models\SeoMeta::create([
            'metable_type' => Post::class,
            'metable_id' => $post->id,
            'title' => ['en' => 'Existing SEO Title'],
        ]);

        event(new PostPublished($post));

        $meta = \App\Models\SeoMeta::where('metable_type', Post::class)->where('metable_id', $post->id)->first();
        $this->assertSame('Existing SEO Title', $meta->getTranslation('title', 'en'), '已有标题不被覆盖');
        $this->assertSame('Generated description text long enough.', $meta->getTranslation('description', 'en'), '只补缺失的描述');
    }
}
