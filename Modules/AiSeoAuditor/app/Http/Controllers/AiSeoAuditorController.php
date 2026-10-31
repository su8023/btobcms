<?php

namespace Modules\AiSeoAuditor\Http\Controllers;

use App\Http\Controllers\Controller;

/**
 * AI SEO 巡检模块的首页占位控制器。
 *
 * 原来这里还带着 nwidart 脚手架生成的 create/show/edit/store/update/destroy：
 *   - create/show/edit 渲染的视图根本不存在 → 访问即 500；
 *   - store/update/destroy 是空方法体 → 返回 200 但什么都没做（静默写入失败）。
 * 这些动作已全部移除，只保留可用的 index。
 */
class AiSeoAuditorController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return view('aiseoauditor::index');
    }
}
