<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Support\Seo;

/**
 * 服务页（/services）：核心服务能力详情 + 询盘转化。
 * 文案驱动自 lang/{locale}/services.php（8 语言）。
 */
class ServiceController extends Controller
{
    public function index()
    {
        return view('pages.services', [
            'services' => [
                ['icon' => 'M12 4v16m-8-8h16', 'title' => __('services.service_1_title'), 'text' => __('services.service_1_text')],
                ['icon' => 'M12 3l8 3v6c0 4.5-3.2 7.7-8 9-4.8-1.3-8-4.5-8-9V6l8-3Z', 'title' => __('services.service_2_title'), 'text' => __('services.service_2_text')],
                ['icon' => 'M5 18h14M5 14h14v4H5Zm2-4l1.5-6h9L18 13M8 13h8', 'title' => __('services.service_3_title'), 'text' => __('services.service_3_text')],
                ['icon' => 'M21 8l-9-5-9 5v8l9 5 9-5V8Z', 'title' => __('services.service_4_title'), 'text' => __('services.service_4_text')],
            ],
            // TDK 统一走 Seo::page（后台设置 → 语言包 → 配置），页面标题含关键词且 ≥25 字符
            'seo' => Seo::page('services.index'),
        ]);
    }
}
