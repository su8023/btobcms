<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\GeoBoost\Models\GeoFaq;
use Modules\TrustBuilder\Models\TrustCertification;
use Modules\TrustBuilder\Models\TrustCase;
use Tests\TestCase;

/**
 * 缓存序列化兼容：database cache 白名单反序列化（禁存 Eloquent 对象），
 * 页面级缓存必须只缓存标量数组——测试默认 array store 不序列化，抓不到这类错，
 * 故显式切 database store 复现生产条件（真实部署模拟中曾抓到 500）。
 */
class CacheSerializationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        // 复现生产：database cache（sqlite 测试库有 cache 表）
        config(['cache.default' => 'database']);
    }

    public function test_trust_bar_with_database_cache_renders(): void
    {
        TrustCertification::create([
            'name' => ['en' => 'ISO 9001', 'es' => 'ISO 9001'],
            'sort' => 1,
        ]);
        TrustCase::create([
            'slug' => 'cache-'.uniqid(),
            'customer_name' => 'ACME',
            'title' => ['en' => 'A case'],
            'body' => ['en' => '<p>x</p>'],
            'published' => true,
        ]);

        // 首次渲染写缓存（含 Eloquent→标量转换），二次渲染读缓存——两遍都必须 200
        $this->get('/')->assertOk()->assertSee('ISO 9001');
        $this->get('/')->assertOk()->assertSee('ISO 9001');

        // 缓存值必须是纯标量（database store 白名单可解）
        $cached = cache()->get('trust-builder:trust-bar');
        $this->assertIsArray($cached);
        $this->assertIsArray($cached['badges']);
    }

    public function test_faq_block_with_database_cache_renders(): void
    {
        $post = \App\Models\Post::create([
            'type' => 'post',
            'slug' => 'cache-faq-'.uniqid(),
            'status' => \App\Models\Post::STATUS_PUBLISHED,
            'published_at' => now(),
            'title' => ['en' => 'Cache FAQ'],
            'content' => ['en' => '<h2>A</h2><p>'.str_repeat('body text. ', 30).'</p>'],
        ]);
        GeoFaq::create([
            'faqable_type' => \App\Models\Post::class,
            'faqable_id' => $post->id,
            'locale' => 'en',
            'sort' => 0,
            'question' => 'MOQ?',
            'answer' => '100 pcs.',
            'source' => 'ai',
        ]);

        $this->get('/blog/'.$post->slug)->assertOk()->assertSee('MOQ?');
        $this->get('/blog/'.$post->slug)->assertOk()->assertSee('MOQ?');

        $cached = cache()->get('geoboost.faqs.'.$post->id.'.en');
        $this->assertIsArray($cached);
        $this->assertSame('MOQ?', $cached[0]['q']);
    }
}
