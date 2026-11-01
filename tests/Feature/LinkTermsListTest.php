<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\LinkWeaver\Filament\Resources\LinkTerms\Pages\ListLinkTerms;
use Modules\LinkWeaver\Models\AppliedLink;
use Modules\LinkWeaver\Models\LinkTerm;
use Tests\TestCase;

/**
 * 内链词典列表回归（v1.4.29）：
 * 曾因计数列名 appliedLinks_count 与 withCount 生成别名 applied_links_count
 * 不一致——列永远取不到值，点击「已织入」列排序时 Unknown column，
 * Livewire 请求 500 → 前台弹「页面加载出错」（线上必现、本地空表不复现）。
 */
class LinkTermsListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(\App\Models\User::firstOrFail());
    }

    private function makeTermWithAppliedLink(): LinkTerm
    {
        $term = LinkTerm::create([
            'keyword' => 'probe keyword', 'target' => '/probe', 'title' => 'T', 'locale' => '', 'enabled' => true,
        ]);

        // seed 不保证产生 Post：测试内自建，不依赖 seed 数据
        $post = \App\Models\Post::create([
            'type' => 'post',
            'slug' => 'probe-'.uniqid(),
            'status' => 'draft',
            'title' => ['en' => 'Probe'],
            'excerpt' => ['en' => 'x'],
            'content' => ['en' => '<p>x</p>'],
        ]);

        AppliedLink::create(['post_id' => $post->id, 'term_id' => $term->id, 'locale' => 'en']);

        return $term;
    }

    public function test_count_column_uses_snake_case_alias(): void
    {
        $this->makeTermWithAppliedLink();

        $row = Livewire::test(ListLinkTerms::class)
            ->instance()
            ->getTable()
            ->getRecords()
            ->first();

        $this->assertNotNull($row);
        // 蛇形别名必须有值（驼峰 appliedLinks_count 永远是 MISSING）
        $this->assertSame(1, (int) ($row->applied_links_count ?? 0));
    }

    public function test_sort_by_count_column_does_not_error(): void
    {
        $this->makeTermWithAppliedLink();

        // 曾因别名不一致在排序时抛 Unknown column —— 修复后必须正常
        Livewire::test(ListLinkTerms::class)
            ->call('sortTable', 'applied_links_count', 'desc')
            ->assertOk();
    }
}
