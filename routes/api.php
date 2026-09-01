<?php

use App\Http\Controllers\ApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public storefront API (CORS-open) for the Reach pixel
|--------------------------------------------------------------------------
*/
Route::get('/pixel-config', [ApiController::class, 'pixelConfig'])->name('api.pixel-config');
Route::post('/track', [ApiController::class, 'track'])->name('api.track');
Route::post('/enrich', [ApiController::class, 'enrich'])->name('api.enrich');

/*
| Local-only mock of the OpenAI Conversions API, so the full server-side
| forwarding path can be exercised end-to-end in development.
*/
if (app()->environment('local')) {
    Route::post('/mock-capi', function (\Illuminate\Http\Request $request) {
        logger()->info('Mock CAPI received event', [
            'event_name' => $request->json('data.0.event_name'),
            'event_id'   => $request->json('data.0.event_id'),
        ]);

        return response()->json(['events_received' => 1]);
    });
}
