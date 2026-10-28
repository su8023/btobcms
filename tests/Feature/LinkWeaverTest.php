<?php

namespace Tests\Feature;

use App\Events\PostPublished;
use App\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Modules\LinkWeaver\Models\AppliedLink;
use Modules\LinkWeaver\Models\LinkTerm;
use Modules\LinkWeaver\Services\Weaver;
use Tests\TestCase;

/**
 * LinkWeaver 智能内链：发布事件 → 正文首次出现处织入词典内链。
 */
class LinkWeaverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        // 同事件上 AutoSeo 也会响应：拦住外部 AI/IndexNow 呼叫
        Http::fake(['*' => Http::response([], 200)]);
        config(['autoseo.key_file' => storage_path('app/indexnow-test.key')]);
    }

    protected function tearDown(): void
    {
        @unlink(storage_path('app/indexnow-test.key'));
        parent::tearDown();
    }

    public function test_weaves_first_occurrence_of_keyword(): void
    {
        LinkTerm::create(['keyword' => 'gate valve manufacturer', 'target' => '/product/gate-valve']);

        $post = Post::create([
            'type' => 'post',
            'slug' => 'weave-'.uniqid(),
            'status' => Post::STATUS_PUBLISHED,
            'title' => ['en' => 'T'],
            // 关键词出现两次：只织第一次
            'content' => ['en' => '<p>The best gate valve manufacturer in China. Another gate valve manufacturer mention.</p>'],
        ]);
        event(new PostPublished($post));

        $html = $post->refresh()->getTranslation('content', 'en');
        $this->assertSame(1, substr_count($html, '<a href="/product/gate-valve"'), '关键词只织首次出现');
        $this->assertStringContainsString('<a href="/product/gate-valve">gate valve manufacturer</a>', $html);
        $this->assertSame(1, AppliedLink::count());
    }

    public function test_never_touches_text_inside_existing_anchor(): void
    {
        LinkTerm::create(['keyword' => 'gate valve manufacturer', 'target' => '/product/gate-valve']);

        $post = Post::create([
            'type' => 'post',
            'slug' => 'anchor-'.uniqid(),
            'status' => Post::STATUS_PUBLISHED,
            'title' => ['en' => 'T'],
            // 已有 a 内是关键词本体 + 一个裸出现：只织裸的那处
            'content' => ['en' => '<p>See <a href="/already">gate valve manufacturer</a> and we are the gate valve manufacturer here.</p>'],
        ]);
        event(new PostPublished($post));

        $html = $post->refresh()->getTranslation('content', 'en');
        $this->assertSame('/already', substr_count($html, '<a href="/already">') > 0 ? '/already' : '');
        $this->assertSame(1, substr_count($html, '<a href="/product/gate-valve"'));
        $this->assertSame(1, substr_count($html, '<a href="/already"'));
    }

    public function test_respects_max_links_per_post(): void
    {
        config(['link-weaver.max_links_per_post' => 1]);
        LinkTerm::create(['keyword' => 'gate valve manufacturer', 'target' => '/a']);
        LinkTerm::create(['keyword' => 'butterfly valve supplier', 'target' => '/b']);

        $post = Post::create([
            'type' => 'post',
            'slug' => 'max-'.uniqid(),
            'status' => Post::STATUS_PUBLISHED,
            'title' => ['en' => 'T'],
            'content' => ['en' => '<p>gate valve manufacturer and butterfly valve supplier together.</p>'],
        ]);
        event(new PostPublished($post));

        $html = $post->refresh()->getTranslation('content', 'en');
        $this->assertSame(1, substr_count($html, '<a href='), '限量 1 条');
    }

    public function test_idempotent_when_event_fires_again(): void
    {
        LinkTerm::create(['keyword' => 'gate valve manufacturer', 'target' => '/product/gate-valve']);

        $post = Post::create([
            'type' => 'post',
            'slug' => 'idem-'.uniqid(),
            'status' => Post::STATUS_PUBLISHED,
            'title' => ['en' => 'T'],
            'content' => ['en' => '<p>gate valve manufacturer here.</p>'],
        ]);
        event(new PostPublished($post));
        event(new PostPublished($post->refresh()));

        $html = $post->refresh()->getTranslation('content', 'en');
        $this->assertSame(1, substr_count($html, '<a href="/product/gate-valve"'), '重发事件不重复织链');
        $this->assertSame(1, AppliedLink::count());
    }

    public function test_locale_scoped_term_only_weaves_that_locale(): void
    {
        LinkTerm::create(['keyword' => 'valve', 'target' => '/x', 'locale' => 'es']);
        // 词典另配一个全语言的
        LinkTerm::create(['keyword' => 'global supplier', 'target' => '/y']);

        $post = Post::create([
            'type' => 'post',
            'slug' => 'locale-'.uniqid(),
            'status' => Post::STATUS_PUBLISHED,
            'title' => ['en' => 'T'],
            'content' => [
                'en' => '<p>valve global supplier text.</p>',
                'es' => '<p>valve global supplier texto.</p>',
            ],
        ]);
        event(new PostPublished($post));

        $en = $post->refresh()->getTranslation('content', 'en');
        $es = $post->refresh()->getTranslation('content', 'es');

        // en：valve 不织（es 专属），global supplier 织
        $this->assertSame(0, substr_count($en, '<a href="/x"'));
        $this->assertSame(1, substr_count($en, '<a href="/y"'));
        // es：两个都织
        $this->assertSame(1, substr_count($es, '<a href="/x"'));
        $this->assertSame(1, substr_count($es, '<a href="/y"'));
    }

    public function test_short_keyword_is_skipped(): void
    {
        LinkTerm::create(['keyword' => 'ab', 'target' => '/x']);

        [$html, $links] = app(Weaver::class)->weaveHtml(
            '<p>ab something</p>',
            LinkTerm::where('keyword', 'ab')->get(),
            3,
        );

        $this->assertSame([], $links);
        $this->assertSame('<p>ab something</p>', $html);
    }

    public function test_weave_html_returns_original_on_garbage(): void
    {
        // 畸形输入不炸、原样返回（织链失败不阻塞发布）
        [$html, $links] = app(Weaver::class)->weaveHtml('', collect(), 3);
        $this->assertSame('', $html);
        $this->assertSame([], $links);
    }

    public function test_reweave_all_applies_to_existing_posts(): void
    {
        $post = Post::create([
            'type' => 'post',
            'slug' => 'reweave-'.uniqid(),
            'status' => Post::STATUS_PUBLISHED,
            'title' => ['en' => 'T'],
            'content' => ['en' => '<p>gate valve manufacturer content.</p>'],
        ]);

        // 先建文章后建词典：回织把历史文章补上
        LinkTerm::create(['keyword' => 'gate valve manufacturer', 'target' => '/product/gate-valve']);

        [$posts, $links] = app(Weaver::class)->reweaveAll();

        $this->assertSame(1, $links);
        $this->assertStringContainsString(
            '<a href="/product/gate-valve"',
            $post->refresh()->getTranslation('content', 'en'),
        );
    }
}
