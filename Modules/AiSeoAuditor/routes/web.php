<?php

use Illuminate\Support\Facades\Route;
use Modules\AiSeoAuditor\Http\Controllers\AiSeoAuditorController;

// 只保留可用的 index：其余资源动作已从控制器移除（见 AiSeoAuditorController 注释）
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('aiseoauditors', [AiSeoAuditorController::class, 'index'])->name('aiseoauditor.index');
});
