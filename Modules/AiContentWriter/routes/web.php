<?php

use Illuminate\Support\Facades\Route;
use Modules\AiContentWriter\Http\Controllers\AiContentWriterController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('aicontentwriters', AiContentWriterController::class)->names('aicontentwriter');
});
