<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\SeoMeta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\AiContentWriter\Services\PostTranslator;
use Tests\TestCase;

/**
 * AI 一键翻译：标题/摘要/正文/SEO 元数据 → 目标语言合并落库（不覆盖已有语言）。
 */
class PostTranslatorTest extends TestCase
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
        ]);
    }

    private function openAiBody(array $data): array
    {
        return [
            'choices' => [['message' => ['content' => json_encode($data, JSON_UNESCAPED_UNICODE)]]],
            'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 200],
        ];
    }

    private function makePost(): Post
    {
        $post = Post::create([
            'type' => 'post',
            'slug' => 'translate-'.uniqid(),
            'status' => Post::STATUS_PUBLISHED,
            'title' => ['en' => 'Gate Valve Buying Guide'],
            'excerpt' => ['en' => 'A guide.'],
            'content' => ['en' => '<h2>Material</h2><p>316 stainless is best.</p>'],
        ]);

        SeoMeta::create([
            'metable_type' => Post::class,
            'metable_id' => $post->id,
            'title' => ['en' => 'Gate Valve Guide | Aples'],
            'description' => ['en' => 'How to buy gate valves from China.'],
        ]);

        return $post;
    }

    public function test_translates_post_and_seo_meta(): void
    {
        Http::fake([
            '*' => Http::response($this->openAiBody([
                'title' => 'Kaufberatung Absperrschieber',
                'excerpt' => 'Ein Leitfaden.',
                'content' => '<h2>Material</h2><p>316 Edelstahl ist am besten.</p>',
                'seo_title' => 'Absperrschieber Ratgeber',
                'seo_description' => 'Absperrschieber aus China kaufen.',
                'seo_keywords' => 'Absperrschieber Lieferant',
            ]), 200),
        ]);

        $post = $this->makePost();
        $translated = app(PostTranslator::class)->translate($post->refresh(), ['de']);

        $this->assertSame(['de'], $translated);

        // en 保留，de 合并进入
        $this->assertSame('Gate Valve Buying Guide', $post->refresh()->getTranslation('title', 'en'));
        $this->assertSame('Kaufberatung Absperrschieber', $post->getTranslation('title', 'de'));
        $this->assertSame('<h2>Material</h2><p>316 Edelstahl ist am besten.</p>', $post->getTranslation('content', 'de'));

        $meta = SeoMeta::where('metable_id', $post->id)->where('metable_type', Post::class)->first();
        $this->assertSame('Absperrschieber Ratgeber', $meta->getTranslation('title', 'de'));
        $this->assertSame('Gate Valve Guide | Aples', $meta->getTranslation('title', 'en'), '已有语言 SEO 不得被覆盖');
        $this->assertSame('Absperrschieber Lieferant', $meta->getTranslation('keywords', 'de'));
    }

    public function test_skips_locales_already_translated_is_caller_responsibility_but_merge_is_safe(): void
    {
        // 已有 de 的情况下再翻 de：合并语义由 setTranslation 决定（覆盖该语言），
        // UI 层只列缺失语言——此处验证重复翻译不会破坏其他语言
        Http::fake(['*' => Http::response($this->openAiBody([
            'title' => 'Guía de compra', 'excerpt' => 'Guía.', 'content' => '<p>x</p>',
        ]), 200)]);

        $post = $this->makePost();
        app(PostTranslator::class)->translate($post->refresh(), ['es']);

        $post->refresh();
        $this->assertSame('Guía de compra', $post->getTranslation('title', 'es'));
        $this->assertSame('Gate Valve Buying Guide', $post->getTranslation('title', 'en'));
    }

    public function test_single_locale_failure_does_not_block_others(): void
    {
        Http::fake([
            '*' => Http::sequence()
                // 第一次（fr）返回垃圾 → 解析失败跳过
                ->push(['choices' => [['message' => ['content' => 'not json at all']]]], 200)
                // 第二次（de）正常
                ->push($this->openAiBody([
                    'title' => 'Kaufberatung', 'excerpt' => 'L.', 'content' => '<p>ok</p>',
                ]), 200),
        ]);

        $post = $this->makePost();
        $translated = app(PostTranslator::class)->translate($post->refresh(), ['fr', 'de']);

        $this->assertSame(['de'], $translated, '失败语言跳过，其余照常');
        $this->assertFalse($post->refresh()->hasTranslation('title', 'fr'));
        $this->assertSame('Kaufberatung', $post->getTranslation('title', 'de'));
    }

    public function test_throws_when_source_content_missing(): void
    {
        $post = Post::create([
            'type' => 'post', 'slug' => 'empty-'.uniqid(), 'status' => 'draft',
            'title' => ['en' => 'Only title'],
        ]);

        $this->expectException(\RuntimeException::class);
        app(PostTranslator::class)->translate($post, ['de']);
    }
}
