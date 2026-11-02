<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AiContentWriter\Support\TranslationProgress;
use Tests\TestCase;

/**
 * AI 翻译实时进度（TranslationProgress）生命周期：
 * 排队 → 翻译中 → 完成/失败 的读写、文章索引维护、在途判断。
 * 覆盖率页的「补翻进度」徽章区靠它渲染。
 */
class TranslationProgressTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(User::first());
    }

    public function test_mark_and_read_lifecycle(): void
    {
        TranslationProgress::mark(1, 'de', 'queued');
        TranslationProgress::mark(1, 'de', 'running');
        TranslationProgress::mark(1, 'en', 'queued');
        TranslationProgress::mark(1, 'en', 'done');

        $entries = TranslationProgress::forPost(1);

        // 排序：running(进行中) 在前，done 在后
        $byLocale = collect($entries)->keyBy('locale');
        $this->assertSame('running', $byLocale['de']['state']);
        $this->assertSame('done', $byLocale['en']['state']);

        // 仍有在途任务
        $this->assertTrue(TranslationProgress::hasInFlight(1));
    }

    public function test_all_terminal_is_not_in_flight(): void
    {
        TranslationProgress::mark(2, 'de', 'done');
        TranslationProgress::mark(2, 'fr', 'failed');

        $this->assertFalse(TranslationProgress::hasInFlight(2));

        // 失败带备注回显
        $byLocale = collect(TranslationProgress::forPost(2))->keyBy('locale');
        $this->assertSame('failed', $byLocale['fr']['state']);
    }

    public function test_tracked_posts_index_and_clear(): void
    {
        TranslationProgress::mark(10, 'de', 'queued');
        TranslationProgress::mark(11, 'de', 'done');

        $this->assertEqualsCanonicalizing([10, 11], TranslationProgress::trackedPosts());

        // clear 同时摘除文章索引，避免进度区出现空卡片
        TranslationProgress::clear(10);
        $this->assertSame([11], TranslationProgress::trackedPosts());
        $this->assertSame([], TranslationProgress::forPost(10));
    }

    public function test_note_is_truncated_and_safe(): void
    {
        TranslationProgress::mark(3, 'de', 'failed', str_repeat('x', 500));

        $entry = collect(TranslationProgress::forPost(3))->firstWhere('locale', 'de');

        $this->assertLessThanOrEqual(200, strlen($entry['note']), '长备注须截断，防撑爆缓存与页面');
    }
}
