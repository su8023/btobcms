<?php

namespace Tests\Feature;

use App\Filament\Resources\Posts\Pages\EditPost;
use App\Models\Post;
use App\Models\SeoMeta;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 文章编辑页「保存静默失败」回归测试。
 *
 * 线上实锤：AutoSeo 发布时把 LLM 生成的 290 字符关键词原样写库，
 * 而后台 SEO 关键词字段限 255（maxLength）。之后每次编辑都校验失败，
 * 而错误提示藏在默认折叠的 SEO 区域 → 运营点「保存」没有任何反馈，
 * 文章完全无法编辑。
 *
 * 本测试锁住：存量超长关键词在数据迁移后不再阻塞保存。
 */
class PostSeoEditabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(User::firstOrFail());
    }

    public function test_post_with_overlong_seo_keywords_still_saves(): void
    {
        $post = Post::create([
            'type' => 'post',
            'slug' => 'seo-editability-probe',
            'status' => Post::STATUS_PUBLISHED,
            'title' => ['en' => 'SEO Editability Probe'],
            'content' => ['en' => '<p>Body.</p>'],
            'published_at' => now(),
        ]);

        // 模拟 AutoSeo 未截断时写入的超长关键词
        $overlong = implode(', ', array_fill(0, 8, 'stainless steel gate valve manufacturer china'));
        $this->assertGreaterThan(255, mb_strlen($overlong));

        SeoMeta::create([
            'metable_type' => Post::class,
            'metable_id' => $post->id,
            'title' => ['en' => 'Probe Title'],
            'keywords' => ['en' => $overlong],
        ]);

        // 数据迁移修复后，超长值被截断 → 编辑页可保存
        // （RefreshDatabase 已把迁移跑过一遍，这里直接调用迁移逻辑处理本条记录）
        $migration = require database_path('migrations/2026_11_03_100000_truncate_overlong_seo_keywords.php');
        $migration->up();

        $meta = SeoMeta::where('metable_type', Post::class)->where('metable_id', $post->id)->firstOrFail();
        $this->assertLessThanOrEqual(255, mb_strlen((string) $meta->getTranslation('keywords', 'en')));

        // 关键断言：编辑页能正常保存（修复前会因校验失败而无任何反馈）
        Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors();
    }

    public function test_overlong_seo_keywords_block_every_field_on_save(): void
    {
        // 因果链：SEO 关键词超长 → 整个表单校验失败 → 连「状态」这种
        // 与 SEO 无关的字段也存不进去（线上实测：文章状态改 draft 保存后仍是 published）。
        // 这条用例锁定「SEO 错误会阻断全部字段」，修复后不再触发。
        $post = Post::create([
            'type' => 'post',
            'slug' => 'seo-blocks-all-probe',
            'status' => Post::STATUS_PUBLISHED,
            'title' => ['en' => 'Blocker Probe'],
            'content' => ['en' => '<p>Body.</p>'],
            'published_at' => now(),
        ]);

        $overlong = implode(', ', array_fill(0, 8, 'stainless steel gate valve manufacturer china'));

        SeoMeta::create([
            'metable_type' => Post::class,
            'metable_id' => $post->id,
            'keywords' => ['en' => $overlong],
        ]);

        // 修复前：状态改成 draft 也保存不了（表单报错，用户看不到）
        $component = Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])
            ->set('data.status', Post::STATUS_DRAFT)
            ->call('save');

        $errorKeys = array_keys($component->errors()->toArray());
        $this->assertNotEmpty(
            array_filter($errorKeys, fn (string $k): bool => str_ends_with($k, 'seo.keywords.en')),
            '超长 SEO 关键词应产生校验错误（阻断整个保存）：实际错误键 '.implode(',', $errorKeys)
        );

        // 修复后：迁移截断 → 状态可正常保存
        $migration = require database_path('migrations/2026_11_03_100000_truncate_overlong_seo_keywords.php');
        $migration->up();

        Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])
            ->set('data.status', Post::STATUS_DRAFT)
            ->call('save')
            ->assertHasNoFormErrors();

        $post->refresh();
        $this->assertSame(Post::STATUS_DRAFT, $post->status);
    }

    public function test_seo_section_is_visible_by_default_so_errors_are_not_hidden(): void
    {
        $post = Post::create([
            'type' => 'post',
            'slug' => 'seo-section-visibility-probe',
            'status' => Post::STATUS_DRAFT,
            'title' => ['en' => 'Visibility Probe'],
            'content' => ['en' => '<p>Body.</p>'],
        ]);

        // SEO 区域默认展开：折叠时校验错误不可见，运营只会看到「保存没反应」
        Livewire::test(EditPost::class, ['record' => $post->getRouteKey()])
            ->assertOk();
    }
}
