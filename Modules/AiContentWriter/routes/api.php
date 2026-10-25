<?php

use Illuminate\Support\Facades\Route;
use Modules\AiContentWriter\Http\Controllers\AiContentWriterController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('aicontentwriters', AiContentWriterController::class)->names('aicontentwriter');
});
