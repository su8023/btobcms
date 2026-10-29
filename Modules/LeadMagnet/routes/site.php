<?php

/**
 * LeadMagnet 插件前台路由（由 routes/web.php 的多语言语言组按语言 include）。
 *
 * @var string $locale 当前语言（默认语言组为 'en'，其余组带 /es/ 等前缀）
 */

use Illuminate\Support\Facades\Route;
use Modules\LeadMagnet\Http\Controllers\MagnetDownloadController;

// 路由名语言前缀约定：默认语言不带前缀，其余 "{$locale}."
$name = $locale === default_locale() ? '' : "{$locale}.";

Route::get('/download/{slug}', [MagnetDownloadController::class, 'show'])
    ->name("{$name}magnet.show");

Route::post('/download/{slug}', [MagnetDownloadController::class, 'submit'])
    ->middleware('throttle:10,1') // 每分钟每 IP 最多 10 次，防刷询盘
    ->name("{$name}magnet.submit");
