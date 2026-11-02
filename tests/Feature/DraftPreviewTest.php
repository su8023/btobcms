<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * 草稿签名预览（/blog/{slug}/preview）：
 * 待审草稿凭签名 URL 可预览（AI 写作室「预览草稿」），无签名/篡改 403，
 * 预览不计浏览量、输出 noindex 防收录；正式页行为不受影响。
 */
class DraftPreviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(User::first());
    }

    private function makePost(array $overrides = []): Post
    {
        return Post::create(array_merge([
            'type' => 'post',
            'slug' => 'draft-preview-'.uniqid(),
            'status' => Post::STATUS_REVIEW,
            'title' => ['en' => 'Draft Preview Title'],
            'content' => ['en' => '<p>Draft body</p>'],
            'is_ai_generated' => true,
        ], $overrides));
    }

    public function test_signed_preview_shows_unpublished_draft(): void
    {
        $post = $this->makePost();

        $url = URL::temporarySignedRoute('blog.preview', now()->addHour(), ['slug' => $post->slug]);

        $this->get($url)
            ->assertOk()
            ->assertSee('Draft Preview Title')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertSee('noindex,nofollow', false); // meta robots 同步输出

        // 预览不计浏览量
        $this->assertSame(0, $post->fresh()->views);
    }

    public function test_preview_rejects_unsigned_or_tampered_urls(): void
    {
        $post = $this->makePost();

        // 无签名
        $this->get("/blog/{$post->slug}/preview")->assertForbidden();

        // 篡改 slug 后签名失效
        $url = URL::temporarySignedRoute('blog.preview', now()->addHour(), ['slug' => $post->slug]);
        $this->get(str_replace($post->slug, 'other-slug', $url))->assertForbidden();

        // 过期签名
        $expired = URL::temporarySignedRoute('blog.preview', now()->subMinute(), ['slug' => $post->slug]);
        $this->get($expired)->assertForbidden();
    }

    public function test_preview_404s_for_unknown_slug_even_with_valid_signature(): void
    {
        $url = URL::temporarySignedRoute('blog.preview', now()->addHour(), ['slug' => 'ghost-post']);

        $this->get($url)->assertNotFound();
    }

    public function test_published_post_formal_page_is_unchanged(): void
    {
        $post = $this->makePost([
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => now(),
        ]);

        $this->get("/blog/{$post->slug}")
            ->assertOk()
            ->assertSee('Draft Preview Title');

        $this->assertSame(1, $post->fresh()->views); // 正式页计数不变
    }
}
