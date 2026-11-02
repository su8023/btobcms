<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\AiContentWriter\Jobs\TranslateRecordJob;
use Modules\AiContentWriter\Support\RecordTranslation;
use Modules\AiContentWriter\Support\TranslationProgress;
use Tests\TestCase;

/**
 * 记录级一键翻译（产品/分类）：缺失口径、排队派发、队列任务两段式翻译、
 * 进度状态机与幂等跳过、列表页「翻译」徽标列。
 * 翻译引擎复用 RecordTranslator（文章侧已有全覆盖），此处只测产品/分类接线。
 */
class RecordTranslationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(User::first());
        app()->setLocale('zh_CN'); // 徽标/动作文案断言按生产后台语言

        config([
            'ai.providers.deepseek.key' => 'sk-test-fake',
            'ai.providers.deepseek.base_url' => 'https://api.deepseek.test/v1',
        ]);
    }

    /* ----------------------------------------------------------
     | 造数辅助
     * --------------------------------------------------------- */

    private function makeProduct(array $name = [], array $summary = [], array $description = []): Product
    {
        return Product::create([
            'slug' => 'rt-p-'.uniqid(),
            'status' => 'published',
            'name' => $name,
            'summary' => $summary,
            'description' => $description,
        ]);
    }

    private function makeCategory(array $name = [], array $description = []): Category
    {
        return Category::create([
            'slug' => 'rt-c-'.uniqid(),
            'status' => true,
            'name' => $name,
            'description' => $description,
        ]);
    }

    /** 两段式第 1 段：短字段元数据（小 JSON） */
    private function translateMeta(string $marker): array
    {
        return ['choices' => [['message' => ['content' => json_encode([
            'title' => $marker.' 名称译文',
            'excerpt' => $marker.' 摘要译文',
            'seo_title' => 'SEO '.$marker,
            'seo_description' => 'SEO 描述 '.$marker,
            'seo_keywords' => 'kw1, kw2',
        ], JSON_UNESCAPED_UNICODE)]]]];
    }

    /** 两段式第 2 段：正文裸 HTML */
    private function translateBody(string $marker): array
    {
        return ['choices' => [['message' => ['content' => '<h2>'.$marker.'</h2><p>描述译文</p>']]]];
    }

    /* ----------------------------------------------------------
     | 缺失口径 / 徽标
     * --------------------------------------------------------- */

    public function test_missing_locales_and_badge_reflect_name_coverage(): void
    {
        $enOnly = $this->makeProduct(['en' => 'Pump X']);
        $full = $this->makeProduct(array_fill_keys(site_locales(), 'Name'));

        $missing = RecordTranslation::missingLocales($enOnly, 'name');
        $this->assertNotContains('en', $missing);
        $this->assertCount(7, $missing);

        // 徽标：有缺口 → 缺 7 种（warning）；无缺口 → 完整（success）
        $gapBadge = RecordTranslation::badge($enOnly, 'product');
        $this->assertSame('缺 7 种', $gapBadge['label']);
        $this->assertSame('warning', $gapBadge['color']);
        $this->assertTrue($gapBadge['hasGap']);

        $fullBadge = RecordTranslation::badge($full, 'product');
        $this->assertSame('完整', $fullBadge['label']);
        $this->assertSame('success', $fullBadge['color']);
        $this->assertFalse($fullBadge['hasGap']);
    }

    public function test_badge_shows_in_flight_count_when_jobs_queued(): void
    {
        $product = $this->makeProduct(['en' => 'Pump X']);
        TranslationProgress::mark($product->id, 'es', TranslationProgress::QUEUED, '', 'product');
        TranslationProgress::mark($product->id, 'fr', TranslationProgress::RUNNING, '', 'product');

        $badge = RecordTranslation::badge($product, 'product');

        $this->assertSame('缺 7 种 ⟳2', $badge['label']);
        $this->assertSame('info', $badge['color']);
    }

    public function test_availability_gate_per_type(): void
    {
        $this->assertTrue(RecordTranslation::available('product'));
        $this->assertTrue(RecordTranslation::available('category'));
        $this->assertFalse(RecordTranslation::available('bogus'));
    }

    /* ----------------------------------------------------------
     | 排队派发
     * --------------------------------------------------------- */

    public function test_enqueue_dispatches_one_job_per_missing_locale(): void
    {
        Queue::fake();

        $product = $this->makeProduct(['en' => 'Pump X']);

        $count = RecordTranslation::enqueue($product, 'product');

        $this->assertSame(7, $count);
        Queue::assertPushed(TranslateRecordJob::class, 7);
        Queue::assertPushed(TranslateRecordJob::class,
            fn (TranslateRecordJob $job) => $job->recordId === $product->id
                && $job->type === 'product'
                && $job->locale === 'es');

        // 派发即登记 queued 进度（type 维度）
        $entries = TranslationProgress::forEntity('product', $product->id);
        $this->assertCount(7, $entries);
        $this->assertSame(['queued'], array_values(array_unique(array_column($entries, 'state'))));
        $this->assertTrue(TranslationProgress::hasInFlight($product->id, 'product'));
    }

    public function test_enqueue_skips_records_without_any_source(): void
    {
        Queue::fake();

        // 全部语言的 name 都为空：没有可翻译的源文本 → 0，不派发任务
        $empty = $this->makeProduct();

        $this->assertSame(0, RecordTranslation::enqueue($empty, 'product'));
        Queue::assertNothingPushed();
    }

    /* ----------------------------------------------------------
     | 队列任务执行（两段式引擎）
     * --------------------------------------------------------- */

    public function test_record_job_translates_product_and_writes_progress(): void
    {
        $product = $this->makeProduct(
            ['en' => 'Pump X'],
            ['en' => 'High pressure'],
            ['en' => '<h2>Specs</h2><p>Body</p>'],
        );

        // 产品正文非空：每语言两次调用（元数据 JSON + 正文 HTML）
        Http::fake([
            '*' => Http::sequence()
                ->push($this->translateMeta('ES'))
                ->push($this->translateBody('ES')),
        ]);

        (new TranslateRecordJob($product->id, 'es', 'product'))->handle();

        $product->refresh();
        $this->assertSame('ES 名称译文', $product->getTranslation('name', 'es', false));
        $this->assertSame('ES 摘要译文', $product->getTranslation('summary', 'es', false));
        $this->assertSame('<h2>ES</h2><p>描述译文</p>', $product->getTranslation('description', 'es', false));
        $this->assertSame('Pump X', $product->getTranslation('name', 'en', false)); // 源语言不覆盖

        // SEO 元数据随翻译落库（ProductTranslator 复用引擎的 meta 事务）
        $meta = $product->seoMeta;
        $this->assertNotNull($meta);
        $this->assertSame('SEO ES', $meta->getTranslation('title', 'es', false));

        // 进度终态 done
        $entries = TranslationProgress::forEntity('product', $product->id);
        $this->assertSame('done', collect($entries)->keyBy('locale')['es']['state']);
    }

    public function test_category_job_translates_name_only_with_single_call(): void
    {
        $category = $this->makeCategory(['en' => 'Gate Valves']);

        // 分类正文为空：只发一次短字段调用（调用 B 跳过）
        Http::fake([
            '*' => Http::sequence()->push($this->translateMeta('ES')),
        ]);

        (new TranslateRecordJob($category->id, 'es', 'category'))->handle();

        $category->refresh();
        $this->assertSame('ES 名称译文', $category->getTranslation('name', 'es', false));

        $entries = TranslationProgress::forEntity('category', $category->id);
        $this->assertSame('done', collect($entries)->keyBy('locale')['es']['state']);
    }

    public function test_job_marks_failure_when_llm_output_unparseable(): void
    {
        $product = $this->makeProduct(['en' => 'Pump X']);

        // 元数据返回非 JSON：引擎抛解析失败 → 任务捕获并标记 failed
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => '垃圾输出']]]])]);

        (new TranslateRecordJob($product->id, 'es', 'product'))->handle();

        $entries = TranslationProgress::forEntity('product', $product->id);
        $this->assertSame('failed', collect($entries)->keyBy('locale')['es']['state']);
    }

    public function test_job_skips_locale_that_was_filled_in_the_meantime(): void
    {
        Queue::fake();

        $product = $this->makeProduct(['en' => 'Pump X']);
        RecordTranslation::enqueue($product, 'product');

        // 排队期间人工补上了 es：任务执行时幂等跳过，不再烧 token
        $product->setTranslation('name', 'es', '人工翻译')->save();

        Http::fake(['*' => Http::sequence()->push($this->translateMeta('ES'))->push($this->translateBody('ES'))]);

        (new TranslateRecordJob($product->id, 'es', 'product'))->handle();

        $entries = TranslationProgress::forEntity('product', $product->id);
        $esEntry = collect($entries)->keyBy('locale')['es'];
        $this->assertSame('done', $esEntry['state']);
        $this->assertStringContainsString('跳过', $esEntry['note']);
    }

    /* ----------------------------------------------------------
     | 列表页「翻译」徽标列与动作
     * --------------------------------------------------------- */

    public function test_products_list_renders_translation_badge_and_action(): void
    {
        $this->makeProduct(['en' => 'Pump X']);            // 缺 7 种
        $this->makeProduct(array_fill_keys(site_locales(), 'Name')); // 完整

        \Livewire\Livewire::test(\App\Filament\Resources\Products\Pages\ListProducts::class)
            ->assertSee('翻译')
            ->assertSee('缺 7 种')
            ->assertSee('完整')
            ->assertSee('补翻');
    }

    public function test_categories_list_renders_translation_badge_and_action(): void
    {
        $this->makeCategory(['en' => 'Gate Valves']);

        \Livewire\Livewire::test(\App\Filament\Resources\Categories\Pages\ListCategories::class)
            ->assertSee('翻译')
            ->assertSee('缺 7 种')
            ->assertSee('补翻');
    }
}
