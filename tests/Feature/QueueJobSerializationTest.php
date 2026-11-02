<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Modules\AiContentWriter\Jobs\TranslatePostJob;
use Modules\AiContentWriter\Jobs\TranslateRecordJob;
use Tests\TestCase;

/**
 * 队列任务序列化往返测试。
 *
 * 背景（线上实锤）：TranslatePostJob 构造器用老式 $this->postId = ... 赋值
 * 但未声明属性 → PHP 8.2 动态属性不入对象 schema → database 队列序列化时
 * 丢失 → 反序列化后 handle() 报 Undefined property，任务静默失败。
 *
 * 本地 sync 队列（同进程）与 Queue::fake()（不执行 handle）都测不到这类 bug，
 * 必须真实走 serialize → unserialize 往返断言属性存活。
 */
class QueueJobSerializationTest extends TestCase
{
    use RefreshDatabase;

    public function test_translate_post_job_survives_serialization(): void
    {
        $this->seed();
        $admin = User::firstOrFail();
        $post = Post::create([
            'site_id' => 1,
            'type' => 'blog',
            'slug' => 'serialization-test-post',
            'status' => 'draft',
            'author_id' => $admin->id,
            'title' => ['en' => 'Serialization Test'],
        ]);

        $job = new TranslatePostJob($post->id, 'es');

        // database 队列的真实路径：serialize 写入 jobs.payload → 反序列化执行
        $restored = unserialize(serialize($job));

        $this->assertSame($post->id, $restored->postId, 'postId 在序列化往返后丢失（动态属性不入 payload）');
        $this->assertSame('es', $restored->locale);
        $this->assertSame('ai-translate', $restored->queue);
    }

    public function test_translate_record_job_survives_serialization(): void
    {
        $this->seed();

        $job = new TranslateRecordJob(1, 'es', 'product');

        $restored = unserialize(serialize($job));

        $this->assertSame(1, $restored->recordId);
        $this->assertSame('es', $restored->locale);
        $this->assertSame('product', $restored->type);
    }

    public function test_queued_job_payload_keeps_declared_properties(): void
    {
        // 端到端：真 push 进 database 队列，从 payload 反序列化还原，
        // 断言属性完整——这是线上真实发生的事故路径
        $this->seed();
        $admin = User::firstOrFail();
        $post = Post::create([
            'site_id' => 1,
            'type' => 'blog',
            'slug' => 'serialization-e2e-post',
            'status' => 'draft',
            'author_id' => $admin->id,
            'title' => ['en' => 'Serialization E2E'],
        ]);

        Queue::fake();

        dispatch(new TranslatePostJob($post->id, 'de'));

        Queue::assertPushed(TranslatePostJob::class, function (TranslatePostJob $pushed): bool {
            $restored = unserialize(serialize($pushed));

            return $restored->postId === $pushed->postId
                && $restored->locale === $pushed->locale;
        });
    }
}
