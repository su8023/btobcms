<?php

use App\Http\Controllers\Api\BasketController;
use App\Http\Controllers\Api\InquiryController;
use Illuminate\Support\Facades\Route;

Route::post('/inquiries', [InquiryController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('api.inquiries.store');

Route::get('/basket/products', [BasketController::class, 'index'])
    ->middleware('throttle:60,1')
    ->name('api.basket.products');
