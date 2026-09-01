<?php

namespace App\Services;

use App\Models\Shop;
use Illuminate\Support\Facades\Http;

class OpenAiCapiClient
{
    /**
     * POST one event to the OpenAI Ads Conversions API (Meta CAPI-style).
     *
     * @return array{ok: bool, status?: int, body?: array, error?: string}
     */
    public function send(Shop $shop, array $event): array
    {
        $token = $shop->capi_token ?: config('ads.capi_token');
        $url = $shop->capiEndpoint() ?: config('ads.capi_url');

        if (! $token) {
            return ['ok' => false, 'error' => 'missing_token'];
        }

        $payload = ['data' => [$event]];

        try {
            $response = Http::withToken($token)
                ->timeout(20)
                ->post($url, $payload);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            // Endpoint unreachable — never let this bubble up as a 500.
            return [
                'ok'     => false,
                'status' => null,
                'error'  => $e->getMessage(),
            ];
        }

        return [
            'ok'     => $response->successful(),
            'status' => $response->status(),
            'body'   => $response->json(),
        ];
    }
}
