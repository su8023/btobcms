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

        return redirect()->back(fallback: '/admin');
    }
}
