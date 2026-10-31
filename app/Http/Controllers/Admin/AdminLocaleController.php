<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetAdminLocale;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AdminLocaleController extends Controller
{
    /**
     * 切换后台语言：写入当前用户资料与会话后返回来源页。
     */
    public function switch(Request $request, string $locale): RedirectResponse
    {
        if (! in_array($locale, SetAdminLocale::SUPPORTED_LOCALES, true)) {
            abort(404, 'Unsupported admin locale.');
        }

        $request->user()?->update(['locale' => $locale]);

        $request->session()->put('admin_locale', $locale);

        // 只允许回到站内后台页：
        // back() 会原样采用 Referer 头，而 UrlGenerator 对绝对 URL 不做校验，
        // 于是 Referer: https://evil.tld/ 会让这个"站内"链接把管理员带走（开放重定向）。
        $target = (string) $request->query('redirect_to', '');
        $referer = (string) $request->headers->get('referer', '');

        foreach ([$target, $referer] as $candidate) {
            if ($candidate === '') {
                continue;
            }

            $path = parse_url($candidate, PHP_URL_PATH);

            if (is_string($path) && str_starts_with($path, '/admin')) {
                // 同源校验：Referer 的 host 必须等于当前请求 host
                $host = parse_url($candidate, PHP_URL_HOST);

                if ($host === null || $host === $request->getHost()) {
                    return redirect()->to($path);
                }
            }
        }

        return redirect()->to('/admin');
    }
}
