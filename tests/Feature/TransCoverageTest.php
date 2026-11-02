<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Post;
use App\Models\Product;
use App\Models\SeoMeta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\TransCoverage\Services\CoverageScanner;
use Modules\TransCoverage\Services\TranslationFixer;
use Tests\TestCase;

/**
 * TransCoverage：翻译覆盖率扫描/缓存失效/AI 补翻/看板渲染。
 * 纯查询聚合，无新迁移；RefreshDatabase 内存库自动迁移。
 */
class TransCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(User::first());

        // 清空种子内容，让统计卡断言完全可控；同时清跨用例残留的数组缓存
        Post::query()->delete();
        Product::query()->delete();
        Category::query()->delete();
        Cache::flush();

        config([
            'ai.providers.deepseek.key' => 'sk-test-fake',
            'ai.providers.deepseek.base_url' => 'https://api.deepseek.test/v1',
        ]);
    }

    /* ----------------------------------------------------------
     | 造数辅助
     * --------------------------------------------------------- */

    private function makePost(array $title = [], array $content = [], string $status = Post::STATUS_PUBLISHED, string $type = 'post'): Post
    {
        return Post::create([
            'type' => $type,
            'slug' => 'cov-'.uniqid(),
            'status' => $status,
            'title' => $title,
            'content' => $content,
        ]);
    }

    private function makeProduct(array $name = [], array $summary = []): Product
    {
        return Product::create([
            'slug' => 'cov-p-'.uniqid(),
            'status' => 'published',
            'name' => $name,
            'summary' => $summary,
        ]);
    }

    private function makeCategory(array $name = [], array $description = []): Category
    {
        return Category::create([
            'slug' => 'cov-c-'.uniqid(),
            'status' => true,
            'name' => $name,
            'description' => $description,
        ]);
    }

    /** OpenAI 兼容的翻译响应体（PostTranslator 每语言一次 chat 调用） */
    /** 两段式第 1 段：短字段元数据（小 JSON） */
    private function translateMeta(string $marker): array
    {
        return ['choices' => [['message' => ['content' => json_encode([
            'title' => $marker.' 标题译文',
            'excerpt' => $marker.' 摘要译文',
            'seo_title' => 'SEO '.$marker,
            'seo_description' => 'SEO 描述 '.$marker,
            'seo_keywords' => 'kw1, kw2',
        ], JSON_UNESCAPED_UNICODE)]]]];
    }

    /** 两段式第 2 段：正文裸 HTML */
    private function translateBody(string $marker): array
    {
        return ['choices' => [['message' => ['content' => '<h2>'.$marker.'</h2><p>正文译文</p>']]]];
    }

    /** 从扫描结果里取指定内容行 */
    private function rowOf(array $scan, string $type, int $id): array
    {
        $row = collect($scan['rows'])->first(fn (array $r) => $r['type'] === $type && $r['id'] === $id);
        $this->assertNotNull($row, "扫描结果中找不到 {$type}#{$id}");

        return $row;
    }

    /* ----------------------------------------------------------
     | 扫描结构 / 状态矩阵
     * --------------------------------------------------------- */

    public function test_scanner_outputs_per_locale_statuses_for_posts_and_products(): void
    {
        // en only（标题+正文都只有 en）：en full，其余 7 种全缺
        $enOnly = $this->makePost(['en' => 'Only English'], ['en' => '<p>Body</p>']);
        $product = $this->makeProduct(['en' => 'Pump X'], ['en' => 'Summary']);

        $scan = app(CoverageScanner::class)->scan();

        // 结构：locales 为全站 8 语言，行字段齐全
        $this->assertSame(site_locales(), $scan['locales']);
        $this->assertCount(8, $scan['locales']);
        $row = $this->rowOf($scan, 'post', $enOnly->id);
        $this->assertSame(
            ['type', 'id', 'title', 'statuses', 'missing_count', 'missing_seo'],
            array_keys($row)
        );
        $this->assertSame(array_fill_keys(site_locales(), null), array_map(fn () => null, $row['statuses']));

        // en only：en 完整，其余 7 种全缺；无 SEO 行 → 缺 8 种 SEO
        $this->assertSame('full', $row['statuses']['en']);
        $this->assertSame('missing', $row['statuses']['es']);
        $this->assertSame(7, $row['missing_count']);
        $this->assertSame(8, $row['missing_seo']);
        $this->assertSame('Only English', $row['title']); // 默认语言标题优先展示

        // 产品：name+summary 齐全 → en full；Product 有 seoMeta 关系，缺失 SEO 数应为整数而非 null
        $pRow = $this->rowOf($scan, 'product', $product->id);
        $this->assertSame('full', $pRow['statuses']['en']);
        $this->assertSame(7, $pRow['missing_count']);
        $this->assertIsInt($pRow['missing_seo']);
    }

    public function test_partial_and_missing_statuses_are_distinguished(): void
    {
        // es 只有标题没有正文 → partial
        $titleOnlyEs = $this->makePost(
            ['en' => 'Base', 'es' => 'Base ES'],
            ['en' => '<p>Body</p>'],
        );
        // 全部语言都没有标题和正文 → missing（含草稿状态也要被扫到）
        $empty = $this->makePost([], [], Post::STATUS_DRAFT);

        $scan = app(CoverageScanner::class)->scan();

        $row = $this->rowOf($scan, 'post', $titleOnlyEs->id);
        $this->assertSame('full', $row['statuses']['en']);
        $this->assertSame('partial', $row['statuses']['es']);
        $this->assertSame('missing', $row['statuses']['fr']);

        $emptyRow = $this->rowOf($scan, 'post', $empty->id);
        $this->assertSame('missing', $emptyRow['statuses']['en']);
        $this->assertSame(8, $emptyRow['missing_count']);
        $this->assertSame('(无标题)', $emptyRow['title']); // 无任何翻译时的占位文案
    }

    public function test_title_falls_back_to_first_translated_language(): void
    {
        // 默认语言 en 缺标题，展示第一个有翻译的语言（fr）
        $post = $this->makePost(['fr' => 'Titre français'], ['fr' => '<p>x</p>']);

        $scan = app(CoverageScanner::class)->scan();

        $this->assertSame('Titre français', $this->rowOf($scan, 'post', $post->id)['title']);
    }

    public function test_missing_seo_counts_reflect_seo_meta_translations(): void
    {
        $withSeo = $this->makePost(['en' => 'SEO Post'], ['en' => '<p>b</p>']);
        SeoMeta::create([
            'metable_type' => Post::class,
            'metable_id' => $withSeo->id,
            'title' => ['en' => 'SEO Title'],
            'description' => ['en' => 'SEO Desc'],
        ]);

        $scan = app(CoverageScanner::class)->scan();

        // 已填 en 的 SEO → 只缺其余 7 种
        $this->assertSame(7, $this->rowOf($scan, 'post', $withSeo->id)['missing_seo']);
    }

    public function test_categories_are_scanned_with_name_and_description(): void
    {
        // name+description 齐 → en full；description 空 → partial
        $full = $this->makeCategory(['en' => 'Gate Valves'], ['en' => '<p>Intro</p>']);
        $nameOnly = $this->makeCategory(['en' => 'Ball Valves']);

        $scan = app(CoverageScanner::class)->scan();

        $fullRow = $this->rowOf($scan, 'category', $full->id);
        $this->assertSame('full', $fullRow['statuses']['en']);
        $this->assertSame('missing', $fullRow['statuses']['es']);
        $this->assertSame(7, $fullRow['missing_count']);
        $this->assertSame('Gate Valves', $fullRow['title']);
        $this->assertIsInt($fullRow['missing_seo']); // Category 也有 seoMeta 关系

        $partialRow = $this->rowOf($scan, 'category', $nameOnly->id);
        $this->assertSame('partial', $partialRow['statuses']['en']); // 有名称无描述 → 半缺
        $this->assertSame(8, $partialRow['missing_count']);          // en partial + 其余 7 种 missing
        $this->assertSame('Ball Valves', $partialRow['title']);
    }

    /* ----------------------------------------------------------
     | 排序与统计卡
     * --------------------------------------------------------- */

    public function test_rows_are_sorted_by_missing_count_desc(): void
    {
        $rich = $this->makePost( // en+es 完整 → 缺 6
            ['en' => 'Rich', 'es' => 'Rico'],
            ['en' => '<p>b</p>', 'es' => '<p>c</p>'],
        );
        $enOnly = $this->makePost(['en' => 'Thin'], ['en' => '<p>b</p>']); // 缺 7

        $scan = app(CoverageScanner::class)->scan();

        $this->assertSame(6, $this->rowOf($scan, 'post', $rich->id)['missing_count']);
        $this->assertSame(7, $this->rowOf($scan, 'post', $enOnly->id)['missing_count']);

        $counts = array_column($scan['rows'], 'missing_count');
        $sorted = $counts;
        rsort($sorted);
        $this->assertSame($sorted, $counts, '矩阵行未按缺失语言数降序排列');
    }

    public function test_stats_cards_match_rows(): void
    {
        $this->makePost(['en' => 'A'], ['en' => '<p>a</p>']);                       // 缺 7
        $this->makePost(['en' => 'B', 'es' => 'B es'], ['en' => '<p>b</p>']);       // es partial，缺 7
        $this->makePost(['en' => 'C', 'es' => 'C es'], ['en' => '<p>c</p>', 'es' => '<p>ce</p>']); // 缺 6
        $this->makePost([], [], Post::STATUS_REVIEW);                                // 缺 8
        $this->makeProduct(['en' => 'P1'], ['en' => 's']);                          // 缺 7
        $this->makeProduct(['en' => 'P2', 'es' => 'P2 es'], ['en' => 's', 'es' => 'se']); // 缺 6

        $stats = app(CoverageScanner::class)->scan()['stats'];

        $this->assertSame(6, $stats['total']);          // 内容总数 = 文章 4 + 产品 2
        $this->assertSame(0, $stats['full_count']);     // 无一完全翻译
        $this->assertSame(0, $stats['full_rate']);
        $this->assertSame(7 + 7 + 6 + 8 + 7 + 6, $stats['total_gaps']); // 总缺口 = 41
        $this->assertSame(48, $stats['seo_gaps']);      // 6 条全部无 SEO → 6 × 8
    }

    /* ----------------------------------------------------------
     | 缓存行为：内容变化 → max(updated_at) 变化 → 重算
     * --------------------------------------------------------- */

    public function test_cache_is_recalculated_when_content_changes(): void
    {
        $scanner = app(CoverageScanner::class);
        $post = $this->makePost(['en' => 'Before'], ['en' => '<p>old</p>']);

        $first = $scanner->scan();
        $key = $scanner->cacheKey();
        $this->assertTrue(Cache::has($key), '扫描结果应已进入缓存');
        $this->assertSame('missing', $first['rows'][0]['statuses']['es']);

        // 改内容：补上 es 的标题+正文，并显式抬高 updated_at（秒级精度下与旧指纹必然不同），触发缓存 key 更换
        $post->forceFill([
            'title' => ['en' => 'Before', 'es' => 'Antes'],
            'content' => ['en' => '<p>old</p>', 'es' => '<p>nuevo</p>'],
            'updated_at' => now()->addSeconds(3),
        ])->save();

        $this->assertNotSame($key, $scanner->cacheKey(), '内容变化后缓存 key 应更换');

        $second = $scanner->scan();
        $row = $this->rowOf($second, 'post', $post->id);
        $this->assertSame('full', $row['statuses']['es'], '重算后应反映新补的 es 翻译');
    }

    public function test_missing_locales_for_lists_non_full_languages(): void
    {
        $scanner = app(CoverageScanner::class);
        $post = $this->makePost(['en' => 'Base'], ['en' => '<p>b</p>']);
        $scanner->scan();

        $missing = $scanner->missingLocalesFor('post', $post->id);

        // 除 en（full）外的 7 种语言都是补翻目标（partial 也算缺）
        $this->assertNotContains('en', $missing);
        $this->assertCount(7, $missing);
        $this->assertSame([], $scanner->missingLocalesFor('post', 999999));
    }

    /* ----------------------------------------------------------
     | AI 补翻（服务层）
     * --------------------------------------------------------- */

    public function test_ai_fix_translates_missing_locales_and_clears_cache(): void
    {
        $this->assertTrue(TranslationFixer::available(), '测试环境应已安装 AiContentWriter 插件');

        $scanner = app(CoverageScanner::class);
        $post = $this->makePost(['en' => 'Gate Valve Guide'], ['en' => '<h2>Material</h2><p>Body</p>']);
        $scanner->scan();
        $keyBefore = $scanner->cacheKey();

        // 两段式：每语言两次调用（元数据 JSON + 正文 HTML），es、fr 顺序区分
        Http::fake([
            '*' => Http::sequence()
                ->push($this->translateMeta('ES'))
                ->push($this->translateBody('ES'))
                ->push($this->translateMeta('FR'))
                ->push($this->translateBody('FR')),
        ]);

        $translated = app(TranslationFixer::class)->fix($post, ['es', 'fr']);

        $this->assertSame(['es', 'fr'], $translated);

        // 翻译落库：标题/正文多语言齐全，且不覆盖源语言
        $post->refresh();
        $titles = $post->getTranslations('title');
        $this->assertSame('Gate Valve Guide', $titles['en']);
        $this->assertSame('ES 标题译文', $titles['es']);
        $this->assertSame('FR 标题译文', $titles['fr']);
        $this->assertSame('<h2>ES</h2><p>正文译文</p>', $post->getTranslation('content', 'es', false));

        // SEO 元数据也补翻落库（PostTranslator 顺带创建 SeoMeta 行）
        $meta = $post->seoMeta;
        $this->assertNotNull($meta);
        $this->assertSame('SEO ES', $meta->getTranslation('title', 'es', false));

        // 缓存清理：补翻前的旧 key 被显式清掉；新指纹下重算，es/fr 变为 full
        $this->assertFalse(Cache::has($keyBefore), '补翻后旧覆盖率缓存应被清除');
        $row = $this->rowOf($scanner->scan(), 'post', $post->id);
        $this->assertSame('full', $row['statuses']['es']);
        $this->assertSame('full', $row['statuses']['fr']);
        $this->assertSame(5, $row['missing_count']); // 8 语言 - en/es/fr 3 种完整 = 缺 5
    }

    public function test_ai_fix_rejects_empty_targets(): void
    {
        $post = $this->makePost(['en' => 'T'], ['en' => '<p>b</p>']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('没有缺失语言');

        app(TranslationFixer::class)->fix($post, []);
    }

    /* ----------------------------------------------------------
     | 看板页渲染
     * --------------------------------------------------------- */

    public function test_coverage_page_renders_matrix_and_stats(): void
    {
        $post = $this->makePost(['en' => 'Valve Guide'], ['en' => '<p>b</p>']);
        $this->makeProduct(['en' => 'Pump'], ['en' => 's']);

        $this->get('/admin/trans-coverage')
            ->assertOk()
            ->assertSee('翻译覆盖率')
            ->assertSee('内容总数')
            ->assertSee('完全翻译率')
            ->assertSee('待补翻的语言缺口')
            ->assertSee('覆盖矩阵')
            ->assertSee('缺 SEO')
            ->assertSee('AI 补翻缺失语言')
            // 矩阵行内容与状态符号
            ->assertSee('Valve Guide')
            ->assertSee('Pump')
            ->assertSee('✓')
            ->assertSee('✗')
            // 产品行无 SEO 列数据时渲染占位符（此例产品有 seoMeta 关系，会输出数字；
            // 仍断言列标题存在即可）
            ->assertSee(strtoupper('es'));
    }
    public function test_ai_fix_dispatches_queue_jobs_when_queue_available(): void
    {
        // 回归：覆盖率页「AI 补翻」曾同步翻多语言（与编辑页同款超时问题），
        // 队列可用时必须按语言拆任务派发
        config(['queue.default' => 'database']);
        Queue::fake();

        $post = $this->makePost(['en' => 'Gate Valve Guide'], ['en' => '<h2>Material</h2><p>Body</p>']);
        app(CoverageScanner::class)->scan();

        \Livewire\Livewire::test(\Modules\TransCoverage\Filament\Pages\CoveragePage::class)
            ->set('fixPostId', (string) $post->id)
            ->call('runFix');

        // 8 语言 - en（full）= 7 个补翻任务
        Queue::assertPushed(\Modules\AiContentWriter\Jobs\TranslatePostJob::class, 7);

        // 派发即写入 queued 进度：页面「补翻进度」区据此展示
        $progress = \Modules\AiContentWriter\Support\TranslationProgress::forPost($post->id);
        $this->assertCount(7, $progress);
        $this->assertSame(['queued'], array_values(array_unique(array_column($progress, 'state'))));
        $this->assertTrue(\Modules\AiContentWriter\Support\TranslationProgress::hasInFlight($post->id));

        // 进度区随页面渲染出现
        \Livewire\Livewire::test(\Modules\TransCoverage\Filament\Pages\CoveragePage::class)
            ->assertSee('补翻进度')
            ->assertSee('排队中');
    }

    public function test_progress_area_lists_product_and_category_jobs(): void
    {
        $product = $this->makeProduct(['en' => 'Pump X'], ['en' => 's']);
        $category = $this->makeCategory(['en' => 'Gate Valves']);

        // 产品/分类翻译任务同样登记进度（type 维度），进度区应一起列出
        \Modules\AiContentWriter\Support\TranslationProgress::mark($product->id, 'es', 'queued', '', 'product');
        \Modules\AiContentWriter\Support\TranslationProgress::mark($product->id, 'fr', 'running', '', 'product');
        \Modules\AiContentWriter\Support\TranslationProgress::mark($category->id, 'de', 'queued', '', 'category');

        \Livewire\Livewire::test(\Modules\TransCoverage\Filament\Pages\CoveragePage::class)
            ->assertSee('补翻进度')
            ->assertSee('Pump X')      // 实体标题取自各类型主字段
            ->assertSee('Gate Valves')
            ->assertSee('产品')
            ->assertSee('分类')
            ->assertSee('翻译中')
            ->assertSee('排队中');
    }
}
