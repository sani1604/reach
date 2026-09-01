<?php

use App\Http\Controllers\ApiController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PixelController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\ShopifyAuthController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public entry points
|--------------------------------------------------------------------------
*/
Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/landing', [HomeController::class, 'landing'])->name('landing');
Route::get('/pixel.js', [PixelController::class, 'script'])->name('pixel.script');

/*
|--------------------------------------------------------------------------
| Shopify OAuth
|--------------------------------------------------------------------------
*/
Route::prefix('auth')->group(function () {
    Route::get('/install', [ShopifyAuthController::class, 'install'])->name('auth.install');
    Route::get('/callback', [ShopifyAuthController::class, 'callback'])->name('auth.callback');
    Route::get('/login', [ShopifyAuthController::class, 'login'])->name('auth.login');
});

/*
|--------------------------------------------------------------------------
| Embedded app (session-auth via VerifyShopifyRequest)
|--------------------------------------------------------------------------
*/
Route::middleware('shopify.request')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/live', [DashboardController::class, 'live'])->name('dashboard.live');
    Route::get('/dashboard/stream', [DashboardController::class, 'stream'])->name('dashboard.stream');

    Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
    Route::post('/settings', [SettingsController::class, 'save'])->name('settings.save');
    Route::post('/settings/test', [SettingsController::class, 'testCapi'])->name('settings.test');

    Route::get('/billing', [BillingController::class, 'index'])->name('billing');
    Route::post('/billing/upgrade', [BillingController::class, 'upgrade'])->name('billing.upgrade');
    Route::get('/billing/confirm', [BillingController::class, 'confirm'])->name('billing.confirm');
    Route::post('/billing/cancel', [BillingController::class, 'cancel'])->name('billing.cancel');
});

/*
|--------------------------------------------------------------------------
| Shopify webhooks (HMAC-verified)
|--------------------------------------------------------------------------
*/
Route::middleware('shopify.webhook')->post('/webhooks', [WebhookController::class, 'handle'])
    ->name('webhooks');
