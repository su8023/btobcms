<?php

use Illuminate\Support\Facades\Route;
use Modules\AiContentWriter\Http\Controllers\StudioController;

// AI 写作室：后台管理员专用（web 中间件带 session/CSRF）
// throttle:30,1 —— 每次调用都消耗运营方的 LLM 额度，必须有速率上限
Route::middleware(['web', 'auth', 'throttle:30,1'])->prefix('admin/ai-studio')->name('ai-studio.')->group(function () {
    Route::post('plan', [StudioController::class, 'plan'])->name('plan');
    Route::post('write', [StudioController::class, 'write'])->name('write');
});
