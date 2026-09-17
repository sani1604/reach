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
        // Accept both the official OpenAI shape (events[]) and the legacy
        // Meta-style shape (data[]) so local end-to-end tests keep working.
        $events = $request->json('events') ?? $request->json('data') ?? [];
        $first = is_array($events) ? ($events[0] ?? []) : [];

        logger()->info('Mock CAPI received event', [
            'pid'          => $request->query('pid'),
            'type'         => $first['type'] ?? $first['event_name'] ?? null,
            'id'           => $first['id'] ?? $first['event_id'] ?? null,
            'validate_only'=> $request->json('validate_only'),
            'count'        => is_array($events) ? count($events) : 0,
        ]);

        return response()->json([
            'events_received' => is_array($events) ? count($events) : 1,
            'validate_only'   => (bool) $request->json('validate_only'),
        ]);
    });
}
