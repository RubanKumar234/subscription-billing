<?php

use App\Http\Controllers\UsageController;
use App\Http\Controllers\MerchantDashboardController;
use Illuminate\Support\Facades\Route;

Route::post('/usage', [UsageController::class, 'store'])
    ->middleware(['merchant.key', 'throttle:usage']);

Route::get('/merchants/{id}/dashboard', [MerchantDashboardController::class, 'show'])
    ->middleware('merchant.key');
