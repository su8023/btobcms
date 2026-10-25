<?php

use Illuminate\Support\Facades\Route;
use Modules\AiSeoAuditor\Http\Controllers\AiSeoAuditorController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('aiseoauditors', AiSeoAuditorController::class)->names('aiseoauditor');
});
