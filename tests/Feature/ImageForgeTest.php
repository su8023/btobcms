<?php

namespace Tests\Feature;

use App\Models\Attachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\ImageForge\Jobs\ConvertAttachmentImage;
use Modules\ImageForge\Services\WebpConverter;
use Tests\TestCase;

/**
 * ImageForge：WebP 转换器 / 帮助函数 / 批量命令 / 附件事件派发 / 后台看板。
 */
class ImageForgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->actingAs(User::first());
        Storage::fake('public');
    }

    /**
     * 用 GD 动态生成一张纯色 PNG 并写入 fake public 盘（不依赖任何测试夹具文件）。
     */
    private function putFakePng(string $path, int $width = 2000, int $height = 1500): void
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, imagecolorallocate($image, 30, 90, 160));

        $stream = fopen('php://memory', 'r+b');
        imagepng($image, $stream);
        rewind($stream);
        $bytes = (string) stream_get_contents($stream);
        fclose($stream);
        imagedestroy($image);

        Storage::disk('public')->put($path, $bytes);
    }

    public function test_converter_creates_webp_sibling_and_scales_to_max_edge(): void
    {
        $this->putFakePng('forge/photo.png', 2000, 1500);

        $result = app(WebpConverter::class)->convert('forge/photo.png');

        $this->assertSame('forge/photo.png.webp', $result);

        $disk = Storage::disk('public');
        $this->assertTrue($disk->exists('forge/photo.png.webp'), '应生成 .webp 兄弟文件');
        $this->assertTrue($disk->exists('forge/photo.png'), '原图必须保留');

        $bytes = (string) $disk->get('forge/photo.png.webp');
        $this->assertSame('RIFF', substr($bytes, 0, 4), 'webp 应为 RIFF 容器');
        $this->assertSame('WEBP', substr($bytes, 8, 4), '偏移 8-11 应为 WEBP 签名');

        // 最长边 2000 缩到 1600：2000x1500 → 1600x1200
        $info = getimagesizefromstring($bytes);
        $this->assertSame([1600, 1200], [$info[0], $info[1]], '应等比缩放到最长边 1600');
    }

    public function test_converter_does_not_scale_small_images(): void
    {
        $this->putFakePng('forge/small.png', 800, 600);

        app(WebpConverter::class)->convert('forge/small.png');

        $info = getimagesizefromstring((string) Storage::disk('public')->get('forge/small.png.webp'));
        $this->assertSame([800, 600], [$info[0], $info[1]], '未超限的图不应缩放');
    }

    public function test_converter_is_idempotent(): void
    {
        $this->putFakePng('forge/photo.png');

        $converter = app(WebpConverter::class);
        $this->assertNotNull($converter->convert('forge/photo.png'));

        // 篡改 webp 内容为哨兵值，并把原图 mtime 调旧：二次调用应命中幂等不再生成
        $disk = Storage::disk('public');
        $disk->put('forge/photo.png.webp', 'sentinel-not-webp');
        touch($disk->path('forge/photo.png'), time() - 3600);

        $this->assertSame('forge/photo.png.webp', $converter->convert('forge/photo.png'));
        $this->assertSame('sentinel-not-webp', $disk->get('forge/photo.png.webp'), '幂等：webp 不应被重新生成');
    }

    public function test_converter_returns_null_for_non_image_or_missing(): void
    {
        $disk = Storage::disk('public');
        $disk->put('forge/readme.txt', 'plain text');
        $disk->put('forge/broken.png', 'definitely not an image');

        $converter = app(WebpConverter::class);

        $this->assertNull($converter->convert('forge/readme.txt'), '非图片应返回 null');
        $this->assertNull($converter->convert('forge/broken.png'), '损坏图片应返回 null');
        $this->assertNull($converter->convert('forge/missing.png'), '不存在的文件应返回 null');
    }

    public function test_src_helper_prefers_webp_and_falls_back(): void
    {
        $disk = Storage::disk('public');
        $this->putFakePng('forge/a.png', 400, 300);
        $disk->put('forge/b.png', 'x');
        $disk->put('forge/b.png.webp', 'fake-webp');

        $this->assertSame($disk->url('forge/a.png'), imageforge_src('forge/a.png'), '无 webp 时回退原图 URL');
        $this->assertSame($disk->url('forge/b.png.webp'), imageforge_src('forge/b.png'), '有 webp 时返回 webp URL');
        $this->assertSame('', imageforge_src(null), '空值安全：返回空串');
    }

    public function test_picture_helper_outputs_picture_with_webp_and_lazy_img(): void
    {
        $disk = Storage::disk('public');
        $this->putFakePng('forge/hero.png', 800, 600);
        $disk->put('forge/hero.png.webp', 'fake-webp-bytes');

        $html = (string) imageforge_picture('forge/hero.png', 'A <hero> & more', ['class' => 'w-full']);

        $this->assertStringContainsString('<picture>', $html);
        $this->assertStringContainsString(
            '<source type="image/webp" srcset="'.e($disk->url('forge/hero.png.webp')).'">',
            $html
        );
        $this->assertStringContainsString('loading="lazy"', $html);
        $this->assertStringContainsString('alt="A &lt;hero&gt; &amp; more"', $html, 'alt 需转义');
        $this->assertStringContainsString('class="w-full"', $html, 'attrs 需合并到 img 标签');
        $this->assertStringContainsString('src="'.e($disk->url('forge/hero.png')).'"', $html);
    }

    public function test_picture_helper_falls_back_to_single_lazy_img(): void
    {
        $this->putFakePng('forge/hero.png', 800, 600);

        $html = (string) imageforge_picture('forge/hero.png', 'hero');

        $this->assertStringNotContainsString('<picture>', $html, '无 webp 时不应输出 picture');
        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString('loading="lazy"', $html);
        $this->assertStringContainsString(Storage::disk('public')->url('forge/hero.png'), $html);

        // 空路径安全：返回空串
        $this->assertSame('', (string) imageforge_picture(null, 'alt'));
    }

    public function test_artisan_command_converts_and_reports_stats(): void
    {
        $this->putFakePng('forge/one.png', 1000, 800);
        $this->putFakePng('forge/two.jpg', 900, 700);
        Storage::disk('public')->put('forge/note.txt', 'not an image, not scanned');

        $this->artisan('imageforge:convert')
            ->expectsOutputToContain('已转换 2，跳过 0，失败 0')
            ->assertExitCode(0);

        $disk = Storage::disk('public');
        $this->assertTrue($disk->exists('forge/one.png.webp'));
        $this->assertTrue($disk->exists('forge/two.jpg.webp'));

        // 原图 mtime 调旧后重跑：全部命中幂等 → 记为跳过
        foreach (['forge/one.png', 'forge/two.jpg'] as $path) {
            touch($disk->path($path), time() - 3600);
        }

        $this->artisan('imageforge:convert')
            ->expectsOutputToContain('已转换 0，跳过 2，失败 0');
    }

    public function test_attachment_created_dispatches_convert_job(): void
    {
        Queue::fake();

        // 图片附件 → 派发
        Attachment::create([
            'attachable_type' => 'product', 'attachable_id' => 1,
            'disk' => 'public', 'path' => 'forge/product.png', 'mime' => 'image/png',
        ]);
        Queue::assertPushed(ConvertAttachmentImage::class, 1);

        // 非图片附件 → 不派发
        Attachment::create([
            'attachable_type' => 'product', 'attachable_id' => 1,
            'disk' => 'public', 'path' => 'docs/spec.pdf', 'mime' => 'application/pdf',
        ]);
        Queue::assertPushed(ConvertAttachmentImage::class, 1);

        // 已是 webp 的附件 → 不派发
        Attachment::create([
            'attachable_type' => 'product', 'attachable_id' => 1,
            'disk' => 'public', 'path' => 'forge/already.webp', 'mime' => 'image/webp',
        ]);
        Queue::assertPushed(ConvertAttachmentImage::class, 1);
    }

    public function test_attachment_created_converts_in_sync_queue(): void
    {
        // phpunit 环境 QUEUE_CONNECTION=sync：created 事件同步执行 job，直接产出 webp
        $this->putFakePng('forge/product.png', 1800, 1200);

        Attachment::create([
            'attachable_type' => 'product', 'attachable_id' => 1,
            'disk' => 'public', 'path' => 'forge/product.png', 'mime' => 'image/png',
        ]);

        $this->assertTrue(Storage::disk('public')->exists('forge/product.png.webp'));
        $this->assertTrue(Storage::disk('public')->exists('forge/product.png'), '原图保留');
    }

    public function test_admin_page_renders_stats(): void
    {
        $this->putFakePng('forge/visible.png', 600, 400);

        $this->get('/admin/image-forge')
            ->assertOk()
            ->assertSee('public 盘图片总数')
            ->assertSee('估算节省空间');
    }
}
