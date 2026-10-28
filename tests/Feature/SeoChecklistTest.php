<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AiContentWriter\Services\SeoChecklist;
use Tests\TestCase;

/**
 * 发布前 SEO 体检（纯规则）：表单 state → 人话问题清单。
 */
class SeoChecklistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    private function goodState(): array
    {
        // 每段约 11 词 × 60 次 ≈ 660 词，满足 ≥600 词检查
        $para1 = str_repeat('Buyer focused procurement guidance about industrial valves and supply chain quality control. ', 60);
        $para2 = str_repeat('More detail on OEM capability and lead time expectations for overseas buyers. ', 60);

        return [
            'title' => ['en' => 'How to Choose a Gate Valve Supplier from China'],
            'excerpt' => ['en' => 'Guide for buyers.'],
            'content' => ['en' => "<h2>Section one</h2><p>{$para1}</p>"
                ."<h2>Section two</h2><p>{$para2}</p>"
                .'<p><img src="/a.jpg" alt="gate valve"> See <a href="/product/gate-valve">gate valve</a>.</p>'],
            'seo' => [
                'title' => ['en' => 'Gate Valve Supplier from China | Aples'],
                // 62 字符 ×2 = 124，落在 70~160
                'description' => ['en' => str_repeat('Trusted gate valve supplier with factory prices and OEM support. ', 2)],
                'keywords' => ['en' => 'gate valve supplier'],
            ],
        ];
    }

    public function test_good_content_passes_clean(): void
    {
        $problems = app(SeoChecklist::class)->checkState($this->goodState());

        $this->assertSame([], $problems, '标准好文应零问题，实际：'.implode(' | ', $problems));
    }

    public function test_empty_state_reports_nothing_to_check(): void
    {
        $problems = app(SeoChecklist::class)->checkState([]);

        $this->assertContains('还没有任何语言的内容可检查', $problems);
    }

    public function test_detects_short_content_missing_h2_links_and_alt(): void
    {
        $problems = app(SeoChecklist::class)->checkState([
            'title' => ['en' => 'Valves'],
            'content' => ['en' => '<p>Short text only.</p><img src="/a.jpg">'],
        ]);

        $joined = implode("\n", $problems);
        $this->assertStringContainsString('正文仅约', $joined);
        $this->assertStringContainsString('H2', $joined);
        $this->assertStringContainsString('缺 alt', $joined);
        $this->assertStringContainsString('没有站内链接', $joined);
        $this->assertStringContainsString('SEO 标题未填', $joined);
    }

    public function test_detects_seo_title_length_and_missing_description(): void
    {
        $problems = app(SeoChecklist::class)->checkState([
            'title' => ['en' => 'T'],
            'content' => ['en' => '<p>x</p>'],
            'seo' => [
                'title' => ['en' => str_repeat('Very long seo title that surely exceeds sixty characters in total length', 1)],
                'description' => ['en' => 'too short'],
            ],
        ]);

        $joined = implode("\n", $problems);
        $this->assertStringContainsString('SEO 标题', $joined, '超长 SEO 标题应提醒');
        $this->assertStringContainsString('SEO 描述', $joined, '过短 SEO 描述应提醒');
    }

    public function test_skips_locales_without_content(): void
    {
        $problems = app(SeoChecklist::class)->checkState($this->goodState() + [
            'es' => [],
        ]);

        $this->assertSame([], $problems);
    }
}
