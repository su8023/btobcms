<?php

use Illuminate\Support\Facades\Route;
use Modules\AiContentWriter\Http\Controllers\StudioController;

// AI 写作室：后台认证用户专用（web 中间件带 session/CSRF）
Route::middleware(['web', 'auth'])->prefix('admin/ai-studio')->name('ai-studio.')->group(function () {
    Route::post('plan', [StudioController::class, 'plan'])->name('plan');
    Route::post('write', [StudioController::class, 'write'])->name('write');
});
