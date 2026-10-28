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
 * 发布事件链完整性：编辑页「保存后发布」与头部「发布」按钮都必须派发 PostPublished
 * （AutoSeo TDK/IndexNow、LinkWeaver 织链全靠该事件，此前两条路径静默丢失）。
 */
class PostPublishEventTest extends TestCase
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

    private function makeDraft(): Post
    {
        return Post::create([
            'type' => 'post',
            'slug' => 'evt-'.uniqid(),
            'status' => Post::STATUS_DRAFT,
            'title' => ['en' => 'Draft post'],
            'content' => ['en' => '<p>'.str_repeat('Body text for the draft article. ', 20).'</p>'],
        ]);
    }

    public function test_publish_header_action_dispatches_post_published(): void
    {
        Event::fake([PostPublished::class]);

        $post = $this->makeDraft();

        \Livewire\Livewire::test(\App\Filament\Resources\Posts\Pages\EditPost::class, ['record' => $post->getKey()])
            ->callAction('publish');

        Event::assertDispatched(PostPublished::class);
    }

    public function test_save_form_transition_draft_to_published_dispatches_event(): void
    {
        Event::fake([PostPublished::class]);

        $post = $this->makeDraft();

        \Livewire\Livewire::test(\App\Filament\Resources\Posts\Pages\EditPost::class, ['record' => $post->getKey()])
            ->set('data.status', 'published')
            ->call('save');

        Event::assertDispatched(PostPublished::class);
    }

    public function test_repeated_publish_does_not_redispatch(): void
    {
        Event::fake([PostPublished::class]);

        $post = $this->makeDraft();
        $post->update(['status' => Post::STATUS_PUBLISHED, 'published_at' => now()]);

        \Livewire\Livewire::test(\App\Filament\Resources\Posts\Pages\EditPost::class, ['record' => $post->getKey()])
            ->call('save');

        Event::assertNotDispatched(PostPublished::class);
    }
}
