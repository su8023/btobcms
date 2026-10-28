<?php

namespace Tests\Feature;

use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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
                    'html' => '<h2>Quality</h2><p>Check AQL 2.5.</p>',
                ]), 200),
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

    public function test_ai_translate_action_translates_and_refills_form(): void
    {
        Http::fake(['*' => Http::response($this->openAiBody([
            'title' => 'Kaufberatung Ventile',
            'excerpt' => 'Leitfaden.',
            'content' => '<h2>Material</h2><p>316 Edelstahl.</p>',
            'seo_title' => 'Ventile Ratgeber',
            'seo_description' => 'Ventile aus China kaufen.',
            'seo_keywords' => 'Ventile Lieferant',
        ]), 200)]);

        $post = Post::create([
            'type' => 'post', 'slug' => 'translate-'.uniqid(), 'status' => 'draft',
            'title' => ['en' => 'Valve Buying Guide'],
            'excerpt' => ['en' => 'A guide.'],
            'content' => ['en' => '<h2>Material</h2><p>316 stainless.</p>'],
        ]);

        \Livewire\Livewire::test(\App\Filament\Resources\Posts\Pages\EditPost::class, ['record' => $post->getKey()])
            ->callAction('aiTranslate', data: ['locales' => ['de']]);

        $post->refresh();
        $this->assertSame('Kaufberatung Ventile', $post->getTranslation('title', 'de'));
        $this->assertSame('Valve Buying Guide', $post->getTranslation('title', 'en'), '已有语言不得被覆盖');
    }

    private function postCount(): \PHPUnit\Framework\Constraint\IsEqual
    {
        // 辅助：表达意图用（断言在测试体内直接写）
        return self::equalTo(0);
    }
}
