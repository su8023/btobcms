<?php

use Illuminate\Support\Facades\Route;
use Modules\AiSeoAuditor\Http\Controllers\AiSeoAuditorController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('aiseoauditors', AiSeoAuditorController::class)->names('aiseoauditor');
});
