<?php

namespace Tests\Feature;

use App\Filament\Resources\Posts\Pages\EditPost;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 「已发布」状态与发布时间的耦合回归。
 *
 * 线上实锤：运营在编辑页状态下拉选「已发布」→ 保存 → 通知「已保存」，
 * 但前台 /blog/{slug} 全站 404——published() 作用域要求 published_at 非空，
 * 而表单路径没人填它。半发布态还让「发布」按钮（visible 条件 status!=='published'）
 * 消失，用户以为系统坏了。
 */
class PostPublishedAtTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(User::first());

        Http::fake(['*' => Http::response([], 200)]);
        config(['autoseo.key_file' => storage_path('app/indexnow-test.key')]);
    }

    protected function tearDown(): void
    {
        @unlink(storage_path('app/indexnow-test.key'));
        parent::tearDown();
    }

    public function test_saving_status_published_fills_published_at_automatically(): void
    {
        $post = Post::create([
            'type' => 'post',
            'slug' => 'pbat-'.uniqid(),
            'status' => Post::STATUS_DRAFT,
            'title' => ['en' => 'Publish without date'],
            'content' => ['en' => '<p>'.str_repeat('Body text for the article. ', 20).'</p>'],
        ]);
        $this->assertNull($post->published_at);

        Livewire::test(EditPost::class, ['record' => $post->getKey()])
            ->set('data.status', 'published')
            ->call('save');

        $this->assertNotNull($post->fresh()->published_at, '状态改已发布并保存后必须自动补发布时间');
    }

    public function test_published_post_with_auto_filled_date_is_visible_on_frontend(): void
    {
        $post = Post::create([
            'type' => 'post',
            'slug' => 'pbat-view-'.uniqid(),
            'status' => Post::STATUS_DRAFT,
            'title' => ['en' => 'Visible after save'],
            'content' => ['en' => '<p>'.str_repeat('Body text for the article. ', 20).'</p>'],
        ]);

        $this->get("/blog/{$post->slug}")->assertNotFound();

        $post->update(['status' => Post::STATUS_PUBLISHED]);

        $this->assertNotNull($post->fresh()->published_at, '模型 update 同样走 saving 兜底');
        $this->get("/blog/{$post->slug}")
            ->assertOk()
            ->assertSee('Visible after save');
    }

    public function test_existing_published_at_is_not_overwritten_on_republish(): void
    {
        $original = now()->subDays(3);
        $post = Post::create([
            'type' => 'post',
            'slug' => 'pbat-keep-'.uniqid(),
            'status' => Post::STATUS_PUBLISHED,
            'published_at' => $original,
            'title' => ['en' => 'Keep first date'],
            'content' => ['en' => '<p>'.str_repeat('Body text. ', 20).'</p>'],
        ]);

        // 草稿→再发布（或任何再次保存），首次发布时间必须保持
        $post->update(['status' => Post::STATUS_DRAFT]);
        $post->update(['status' => Post::STATUS_PUBLISHED]);

        $this->assertEqualsWithDelta(
            $original->getTimestamp(),
            $post->fresh()->published_at->getTimestamp(),
            2,
            '重复发布不得刷新首次发布时间'
        );
    }

    public function test_draft_without_published_at_stays_null(): void
    {
        $post = Post::create([
            'type' => 'post',
            'slug' => 'pbat-draft-'.uniqid(),
            'status' => Post::STATUS_DRAFT,
            'title' => ['en' => 'Still draft'],
            'content' => ['en' => '<p>Body.</p>'],
        ]);

        $post->update(['title' => ['en' => 'Edited draft']]);

        $this->assertNull($post->fresh()->published_at, '草稿保存不应触发补时间');
    }
}
