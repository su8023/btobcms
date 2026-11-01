<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 文章编辑器 AI 动作（PluginPostActions 约定 → AiContentWriter 提供）：
 * Livewire 级调用 Filament action，验证填表/翻译全链路。
 */
class PostEditorActionsTest extends TestCase
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

    public function test_ai_compose_fills_create_form_without_saving(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push($this->openAiBody([
                    'title' => 'How to Source Valves from China',
                    'angle' => 'risk control',
                    'outline' => [['h2' => 'Quality', 'points' => ['AQL']]],
                    'keywords' => ['valve supplier'],
                    'faq' => [['q' => 'MOQ?']],
                ]), 200)
                ->push($this->openAiBody([
                    'slug' => 'source-valves-china',
                    'excerpt' => 'Sourcing guide.',
                ]), 200)
                // 调用 B（正文）：裸 HTML 直出
                ->push(['choices' => [['message' => ['content' => '<h2>Quality</h2><p>Check AQL 2.5.</p>']]], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 2]], 200),
        ]);

        \Livewire\Livewire::test(\App\Filament\Resources\Posts\Pages\CreatePost::class)
            ->callAction('aiCompose', data: [
                'topic' => 'How to source valves from China',
                'lang' => 'en',
                'words' => 800,
            ])
            ->assertSet('data.slug', 'source-valves-china')
            ->assertSet('data.title.en', 'How to Source Valves from China')
            // RichEditor 字段的状态是 Tiptap JSON doc，断言其包含生成的文本
            ->assertSet('data.content.en', fn ($value) => str_contains(json_encode($value), 'Check AQL'))
            ->assertSet('data.seo.keywords.en', 'valve supplier');

        // 关键：填充不落库，由运营检查后自行保存
        $this->assertSame(0, Post::count());
    }

    public function test_edit_page_has_ai_actions_from_plugin(): void
    {
        $post = Post::create([
            'type' => 'post', 'slug' => 'actions-'.uniqid(), 'status' => 'draft',
            'title' => ['en' => 'Existing post'],
            'content' => ['en' => '<p>x</p>'],
        ]);

        \Livewire\Livewire::test(\App\Filament\Resources\Posts\Pages\EditPost::class, ['record' => $post->getKey()])
            ->assertActionVisible('aiCompose')
            ->assertActionVisible('aiTranslate')
            ->assertActionVisible('seoCheck');
    }

    public function test_ai_translate_action_dispatches_queue_jobs_per_locale(): void
    {
        // 回归：多语言同步翻译曾在一个 HTTP 请求里跑 5~10 分钟被服务器掐断
        //（Livewire 报「页面加载出错」）。现在按语言逐个派发队列任务。
        // 测试环境默认 sync（会走内联回退），这里显式声明数据库队列以覆盖派发分支。
        config(['queue.default' => 'database']);
        Queue::fake();

        $post = Post::create([
            'type' => 'post', 'slug' => 'translate-'.uniqid(), 'status' => 'draft',
            'title' => ['en' => 'Valve Buying Guide'],
            'excerpt' => ['en' => 'A guide.'],
            'content' => ['en' => '<h2>Material</h2><p>316 stainless.</p>'],
        ]);

        \Livewire\Livewire::test(\App\Filament\Resources\Posts\Pages\EditPost::class, ['record' => $post->getKey()])
            ->callAction('aiTranslate', data: ['locales' => ['de', 'es']]);

        Queue::assertPushed(\Modules\AiContentWriter\Jobs\TranslatePostJob::class, 2);
        Queue::assertPushed(\Modules\AiContentWriter\Jobs\TranslatePostJob::class,
            fn (\Modules\AiContentWriter\Jobs\TranslatePostJob $job) => $job->postId === $post->id && $job->locale === 'de');
        Queue::assertPushed(\Modules\AiContentWriter\Jobs\TranslatePostJob::class,
            fn (\Modules\AiContentWriter\Jobs\TranslatePostJob $job) => $job->postId === $post->id && $job->locale === 'es');

        // 派发后不立即翻译：任务由调度器逐个消化
        $post->refresh();
        $this->assertFalse($post->hasTranslation('title', 'de'));
    }

    public function test_translate_job_runs_translation_and_is_idempotent(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push($this->openAiBody([
                'title' => 'Kaufberatung Ventile',
                'excerpt' => 'Leitfaden.',
                'seo_title' => 'Ventile Ratgeber',
                'seo_description' => 'Ventile aus China kaufen.',
                'seo_keywords' => 'Ventile Lieferant',
            ]))
            ->push(['choices' => [['message' => ['content' => '<h2>Material</h2><p>316 Edelstahl.</p>']]]]),
        ]);

        $post = Post::create([
            'type' => 'post', 'slug' => 'translate-'.uniqid(), 'status' => 'draft',
            'title' => ['en' => 'Valve Buying Guide'],
            'excerpt' => ['en' => 'A guide.'],
            'content' => ['en' => '<h2>Material</h2><p>316 stainless.</p>'],
        ]);

        (new \Modules\AiContentWriter\Jobs\TranslatePostJob($post->id, 'de'))->handle();

        $post->refresh();
        $this->assertSame('Kaufberatung Ventile', $post->getTranslation('title', 'de'));
        $this->assertSame('Valve Buying Guide', $post->getTranslation('title', 'en'), '已有语言不得被覆盖');

        // 幂等：语言已存在时任务空跑（不重复消耗 LLM 额度）
        Http::fake(['*' => Http::response('should not be called')]);
        (new \Modules\AiContentWriter\Jobs\TranslatePostJob($post->id, 'de'))->handle();
        Http::assertNothingSent();
    }

    private function postCount(): \PHPUnit\Framework\Constraint\IsEqual
    {
        // 辅助：表达意图用（断言在测试体内直接写）
        return self::equalTo(0);
    }
    public function test_seo_check_survives_tiptap_doc_content_state(): void
    {
        // 回归：编辑页 Livewire 状态里 RichEditor 正文是 Tiptap JSON 数组，
        // SEO 体检曾直接 (string) 强转 → 500「Array to string conversion」弹窗必炸
        $post = Post::create([
            'type' => 'post', 'slug' => 'seo-check-'.uniqid(), 'status' => 'draft',
            'title' => ['en' => 'Canton Fair Guide'],
            'excerpt' => ['en' => 'Guide.'],
            'content' => ['en' => '<h2>Phases</h2><p>Three phases per year with enough words for the check to run.</p>'],
        ]);

        // 打开 SEO 体检弹窗（modalDescription 闭包会真实执行 checkState）
        \Livewire\Livewire::test(\App\Filament\Resources\Posts\Pages\EditPost::class, ['record' => $post->getKey()])
            ->callAction('seoCheck');

        // 服务层：Tiptap doc 数组形态必须可用，且 h2/词数规则照常命中
        $doc = [
            'type' => 'doc',
            'content' => [
                ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Phases']]],
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Three phases per year with enough words here to pass the minimum word requirement for the check.']]],
            ],
        ];
        $problems = app(\Modules\AiContentWriter\Services\SeoChecklist::class)->checkState([
            'title' => ['en' => 'Canton Fair Guide'],
            'content' => ['en' => $doc],
        ]);

        $this->assertNotContains(' [en] 小节标题（H2）只有 0 个，建议 ≥2 个', $problems,
            'Tiptap 转换后应能识别出 h2，实际问题: '.implode(' | ', $problems));
    }
}