<?php

use Illuminate\Support\Facades\Route;
use Modules\FeishuNotifier\Http\Controllers\FeishuNotifierController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('feishunotifiers', FeishuNotifierController::class)->names('feishunotifier');
});
