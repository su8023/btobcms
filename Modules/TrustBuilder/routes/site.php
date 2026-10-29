<?php

/**
 * TrustBuilder 插件前台路由（由 routes/web.php 的多语言语言组按语言 include）。
 *
 * @var string $locale 当前语言（默认语言组为 'en'，其余组带 /es/ 等前缀）
 */

use Illuminate\Support\Facades\Route;
use Modules\TrustBuilder\Http\Controllers\Site\CaseController;

// 路由名语言前缀约定：默认语言不带前缀，其余 "{$locale}."
$name = $locale === default_locale() ? '' : "{$locale}.";

// 案例列表 /case-studies
Route::get('/case-studies', [CaseController::class, 'index'])
    ->name("{$name}cases.index");

// 案例详情 /case-studies/{slug}
Route::get('/case-studies/{slug}', [CaseController::class, 'show'])
    ->name("{$name}cases.show");
