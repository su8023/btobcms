<?php

namespace Tests\Feature;

use App\Models\Redirect;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 301 规则变更后的缓存失效。
 *
 * 线上实锤：后台把 301 的目标路径改成新地址，线上仍跳旧地址，
 * 最长可持续 1 小时（RedirectRequests 中间件缓存命中结果 1 小时）。
 * 运营改 301 的预期是「立刻生效」。
 */
class RedirectCacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_editing_redirect_target_invalidates_cache_immediately(): void
    {
        $redirect = Redirect::create([
            'from_path' => '/legacy-old-url',
            'to_path' => '/new-target',
            'code' => 301,
        ]);

        // 模拟中间件已缓存命中结果
        Cache::put(Redirect::cacheKey('/legacy-old-url'), ['to' => '/new-target', 'code' => 301], now()->addHour());

        // 改成新目标
        $redirect->update(['to_path' => '/updated-target']);

        $this->assertNull(
            Cache::get(Redirect::cacheKey('/legacy-old-url')),
            '改 301 目标后缓存必须立即失效（否则线上最长 1 小时跳旧地址）'
        );
    }

    public function test_changing_from_path_invalidates_both_caches(): void
    {
        $redirect = Redirect::create([
            'from_path' => '/old-path',
            'to_path' => '/target',
            'code' => 301,
        ]);

        Cache::put(Redirect::cacheKey('/old-path'), ['to' => '/target', 'code' => 301], now()->addHour());
        Cache::put(Redirect::cacheKey('/new-path'), 0, now()->addMinutes(5));

        $redirect->update(['from_path' => '/new-path']);

        $this->assertNull(Cache::get(Redirect::cacheKey('/old-path')), '旧路径缓存必须失效');
        $this->assertNull(Cache::get(Redirect::cacheKey('/new-path')), '新路径的负缓存必须失效（否则新规则不生效）');
    }

    public function test_deleting_redirect_invalidates_cache(): void
    {
        $redirect = Redirect::create([
            'from_path' => '/to-be-deleted',
            'to_path' => '/target',
            'code' => 301,
        ]);

        Cache::put(Redirect::cacheKey('/to-be-deleted'), ['to' => '/target', 'code' => 301], now()->addHour());

        $redirect->delete();

        $this->assertNull(Cache::get(Redirect::cacheKey('/to-be-deleted')), '删除 301 后缓存必须失效');
    }

    public function test_middleware_serves_updated_target_after_edit(): void
    {
        $redirect = Redirect::create([
            'from_path' => '/live-check',
            'to_path' => '/target-a',
            'code' => 301,
        ]);

        // 先访问一次，填充缓存
        $this->get('/live-check')->assertRedirect('/target-a');

        $redirect->update(['to_path' => '/target-b']);

        // 改完立刻访问应跳新目标（修复前会跳 target-a）
        $this->get('/live-check')->assertRedirect('/target-b');
    }
}
