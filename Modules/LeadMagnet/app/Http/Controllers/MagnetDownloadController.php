<?php

namespace Modules\LeadMagnet\Http\Controllers;

use App\Models\Inquiry;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Modules\LeadMagnet\Mail\MagnetLinkMail;
use Modules\LeadMagnet\Models\LeadMagnet;
use Modules\LeadMagnet\Models\MagnetDownload;

/**
 * 资料磁铁前台控制器：
 *   GET  /download/{slug}          落地页（多语言标题/描述 + 邮箱表单 + honeypot）
 *   POST /download/{slug}          留邮箱 → 建 Inquiry + MagnetDownload → 发签名链接邮件
 *   GET  /download/file/{download} 签名路由（magnet.file，Provider 注册，无语言前缀）→ 流式下载文件
 */
class MagnetDownloadController extends Controller
{
    public function show(Request $request, string $slug)
    {
        // 未启用/不存在的资料一律 404，避免泄露存量 slug
        $magnet = $this->findActive($slug);

        return view('leadmagnet::download', [
            'magnet' => $magnet,
            'noindex' => true, // 下载换邮箱的落地页不让搜索引擎收录
        ]);
    }

    public function submit(Request $request, string $slug)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:191'],
            // honeypot：正常用户看不到也不会填，填写即判定为机器人
            'website' => ['nullable', 'string', 'max:191'],
        ]);

        $magnet = $this->findActive($slug);

        $isSpam = filled($data['website'] ?? null);
        $locale = app()->getLocale();

        // 落地页 URL 上的 utm_* 参数原样带给询盘与下载记录
        $utm = collect($request->query())
            ->filter(fn ($value, $key) => str_starts_with($key, 'utm_'))
            ->all() ?: null;

        // 线索落库：name 取邮箱 @ 前缀，message 带资料标题便于销售识别来源
        $inquiry = Inquiry::create([
            'name' => (string) strstr($data['email'], '@', true) ?: $data['email'],
            'email' => $data['email'],
            'message' => 'LeadMagnet: '.(string) $magnet->title,
            'status' => $isSpam ? Inquiry::STATUS_SPAM : Inquiry::STATUS_NEW,
            'spam_score' => $isSpam ? 100 : 0,
            'source' => 'magnet',
            'utm' => $utm,
            'landing_url' => url()->current(),
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
        ]);

        // 蜜罐命中：只记 spam 询盘，不发下载记录、不发邮件，但照常回成功提示（不惊动机器人）
        if ($isSpam) {
            return $this->redirectToLanding($magnet);
        }

        $download = MagnetDownload::create([
            'magnet_id' => $magnet->id,
            'email' => $data['email'],
            'locale' => $locale,
            'ip' => $request->ip(),
            'utm' => $utm,
        ]);

        // 16 小时有效的签名下载链接（路由名 magnet.file，由 Provider 注册一次）
        $url = URL::temporarySignedRoute('magnet.file', now()->addHours(16), [
            'download' => $download->id,
        ]);

        Mail::to($data['email'])->send(new MagnetLinkMail($magnet, $url));

        return $this->redirectToLanding($magnet);
    }

    /**
     * 签名下载（GET /download/file/{download}，signed 中间件已在路由层校验签名）。
     */
    public function download(MagnetDownload $download)
    {
        $magnet = $download->magnet;
        $disk = Storage::disk('local');

        // 链接有效但文件已被后台删除/移走：404 而不是 500
        abort_unless($magnet && $disk->exists($magnet->file_path), 404);

        // 冗余计数：实际交付文件时 +1
        $magnet->increment('downloads_count');

        // 下载文件名用 slug + 原扩展（存储名是哈希串，对买家不友好）
        return $disk->download(
            $magnet->file_path,
            $magnet->slug.'.'.pathinfo($magnet->file_path, PATHINFO_EXTENSION),
        );
    }

    private function findActive(string $slug): LeadMagnet
    {
        return LeadMagnet::query()->where('slug', $slug)->where('active', true)->firstOrFail();
    }

    private function redirectToLanding(LeadMagnet $magnet)
    {
        // 回当前语言的落地页并 flash 成功提示（localized_route 自动按当前语言补前缀）
        return redirect(localized_route('magnet.show', ['slug' => $magnet->slug]))
            ->with('magnet_success', __('front.success'));
    }
}
