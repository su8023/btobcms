<?php

use Illuminate\Support\Facades\Route;
use Modules\FeishuNotifier\Http\Controllers\FeishuNotifierController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('feishunotifiers', FeishuNotifierController::class)->names('feishunotifier');
});
