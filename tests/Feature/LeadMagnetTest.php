<?php

namespace Tests\Feature;

use App\Models\Inquiry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\LeadMagnet\Mail\MagnetLinkMail;
use Modules\LeadMagnet\Models\LeadMagnet;
use Modules\LeadMagnet\Models\MagnetDownload;
use Tests\TestCase;

/**
 * LeadMagnet 资料磁铁：落地页渲染、留邮箱换签名下载链接、
 * 线索落库（source=magnet）、honeypot 反垃圾、签名校验与文件下载。
 */
class LeadMagnetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        // 文件走 local private 盘假盘，避免真实写盘
        Storage::fake('local');
    }

    /** 造一份磁铁并把假文件写进 local 盘 */
    private function makeMagnet(array $overrides = []): LeadMagnet
    {
        $path = 'lead-magnets/'.Str::random(16).'.pdf';
        Storage::disk('local')->put($path, 'fake catalog bytes');

        return LeadMagnet::create(array_merge([
            'slug' => 'catalog-'.Str::random(6),
            'title' => ['en' => 'Valve Catalog 2026'],
            'description' => ['en' => 'Complete product catalog with full specs.'],
            'file_path' => $path,
            'active' => true,
        ], $overrides));
    }

    /** 从假邮件里取出签名 URL（MagnetLinkMail 的公开属性） */
    private function sentMailUrl(): string
    {
        $url = null;

        Mail::assertSent(MagnetLinkMail::class, function (MagnetLinkMail $mail) use (&$url): bool {
            $url = $mail->url;

            return true;
        });

        $this->assertNotNull($url);

        return $url;
    }

    public function test_landing_page_renders(): void
    {
        $magnet = $this->makeMagnet();

        $this->get('/download/'.$magnet->slug)
            ->assertOk()
            ->assertSee('Valve Catalog 2026', false)
            ->assertSee('Complete product catalog with full specs.', false)
            ->assertSee('name="email"', false)
            ->assertSee('name="website"', false); // honeypot 字段存在
    }

    public function test_submit_creates_inquiry_download_and_mails_signed_link(): void
    {
        Mail::fake();
        $magnet = $this->makeMagnet();

        $this->post('/download/'.$magnet->slug, ['email' => 'buyer@acme.com'])
            ->assertRedirect('/download/'.$magnet->slug)
            ->assertSessionHas('magnet_success');

        // 询盘落库：source=magnet、name 取邮箱前缀、message 带资料标题
        $inquiry = Inquiry::query()->where('source', 'magnet')->sole();
        $this->assertSame('buyer@acme.com', $inquiry->email);
        $this->assertSame('buyer', $inquiry->name);
        $this->assertSame('LeadMagnet: Valve Catalog 2026', $inquiry->message);
        $this->assertSame(Inquiry::STATUS_NEW, $inquiry->status);

        // 下载记录落库
        $download = MagnetDownload::query()->sole();
        $this->assertSame($magnet->id, $download->magnet_id);
        $this->assertSame('buyer@acme.com', $download->email);
        $this->assertSame('en', $download->locale);

        // 邮件已发出，且正文/属性里带签名 URL
        Mail::assertSent(MagnetLinkMail::class, 1);
        $url = $this->sentMailUrl();
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringContainsString('/download/file/', $url);
    }

    public function test_signed_url_downloads_the_file(): void
    {
        Mail::fake();
        $magnet = $this->makeMagnet();

        $this->post('/download/'.$magnet->slug, ['email' => 'buyer@acme.com']);
        $url = $this->sentMailUrl();

        $this->get($url)
            ->assertOk()
            ->assertDownload();

        // 实际交付文件：冗余计数 +1
        $this->assertSame(1, $magnet->fresh()->downloads_count);
    }

    public function test_tampered_signature_is_rejected(): void
    {
        Mail::fake();
        $magnet = $this->makeMagnet();

        $this->post('/download/'.$magnet->slug, ['email' => 'buyer@acme.com']);
        $url = $this->sentMailUrl();

        // 篡改签名 → 403（signed 中间件拒绝）
        $tampered = preg_replace('/signature=[^&]+/', 'signature=tampered', $url);
        $this->get($tampered)->assertForbidden();

        // 签名无效不得交付文件、不得计数
        $this->assertSame(0, $magnet->fresh()->downloads_count);
    }

    public function test_honeypot_hit_marks_spam_without_mail_or_download(): void
    {
        Mail::fake();
        $magnet = $this->makeMagnet();

        $this->post('/download/'.$magnet->slug, [
            'email' => 'bot@spam.example',
            'website' => 'http://spam.example', // 机器人填了蜜罐字段
        ])->assertRedirect('/download/'.$magnet->slug);

        // 询盘照记但标记 spam
        $inquiry = Inquiry::query()->where('email', 'bot@spam.example')->sole();
        $this->assertSame(Inquiry::STATUS_SPAM, $inquiry->status);
        $this->assertSame('magnet', $inquiry->source);

        // 不发下载记录、不发邮件
        $this->assertSame(0, MagnetDownload::count());
        Mail::assertNothingSent();
    }

    public function test_inactive_magnet_returns_404(): void
    {
        Mail::fake();
        $magnet = $this->makeMagnet(['active' => false]);

        $this->get('/download/'.$magnet->slug)->assertNotFound();
        $this->post('/download/'.$magnet->slug, ['email' => 'buyer@acme.com'])->assertNotFound();

        $this->assertSame(0, Inquiry::count());
        Mail::assertNothingSent();
    }

    public function test_unknown_slug_returns_404(): void
    {
        $this->get('/download/nope-404')->assertNotFound();
    }

    public function test_invalid_email_is_rejected(): void
    {
        Mail::fake();
        $magnet = $this->makeMagnet();

        $this->post('/download/'.$magnet->slug, ['email' => 'not-an-email'])
            ->assertSessionHasErrors('email');

        $this->assertSame(0, Inquiry::count());
        $this->assertSame(0, MagnetDownload::count());
        Mail::assertNothingSent();
    }

    public function test_localized_landing_page_falls_back_to_default_translation(): void
    {
        $magnet = $this->makeMagnet();

        // es 页面（标题未翻译时回退 en 文案），表单提交后回 es 落地页
        $this->get('/es/download/'.$magnet->slug)
            ->assertOk()
            ->assertSee('Valve Catalog 2026', false);

        Mail::fake();
        $this->post('/es/download/'.$magnet->slug, ['email' => 'buyer@acme.com'])
            ->assertRedirect('/es/download/'.$magnet->slug);

        // 下载记录记录提交时的站点语言
        $this->assertSame('es', MagnetDownload::query()->sole()->locale);
    }
}
