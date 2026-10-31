<?php

use Illuminate\Support\Facades\Route;
use Modules\AiContentWriter\Http\Controllers\AiContentWriterController;

// 只保留可用的 index：其余资源动作已从控制器移除（见 AiContentWriterController 注释）
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('aicontentwriters', [AiContentWriterController::class, 'index'])->name('aicontentwriter.index');
});
