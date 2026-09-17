<?php

namespace App\Services;

use App\Models\Shop;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI Ads Conversions API client.
 *
 * POST https://bzr.openai.com/v1/events?pid={PIXEL_ID}
 * Authorization: Bearer {CAPI_KEY}
 *
 * @see https://developers.openai.com/ads/conversions-api
 */
class OpenAiCapiClient
{
    /**
     * POST one (or more) events to the OpenAI Ads Conversions API.
     *
     * @param  array  $event  A single event object (from EventMapper::build) or a list of them.
     * @return array{ok: bool, status?: int|null, body?: mixed, error?: string, url?: string}
     */
    public function send(Shop $shop, array $event, bool $validateOnly = false): array
    {
        $token = $shop->capi_token ?: config('ads.capi_token');
        $pixelId = $shop->pixel_id;
        $baseUrl = $shop->capiEndpoint() ?: config('ads.capi_url');

        if (! $token) {
            return ['ok' => false, 'error' => 'missing_token'];
        }

        if (! $pixelId || str_starts_with((string) $pixelId, 'gid://')) {
            return ['ok' => false, 'error' => 'missing_pixel_id'];
        }

        // Accept either a single event or an already-wrapped list.
        $events = $this->normalizeEvents($event);
        if (! $events) {
            return ['ok' => false, 'error' => 'empty_events'];
        }

        $url = $this->buildUrl((string) $baseUrl, (string) $pixelId);

        $payload = [
            'validate_only' => $validateOnly,
            'events'        => $events,
        ];

        $integrationSource = config('ads.integration_source');
        if ($integrationSource) {
            $payload['integration_source'] = $integrationSource;
        }

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->asJson()
                ->timeout(20)
                ->post($url, $payload);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return [
                'ok'     => false,
                'status' => null,
                'error'  => $e->getMessage(),
                'url'    => $url,
            ];
        }

        return [
            'ok'     => $response->successful(),
            'status' => $response->status(),
            'body'   => $response->json() ?? $response->body(),
            'url'    => $url,
        ];
    }

    /**
     * Optional: probe the Advertiser / Ads Manager API key (api.ads.openai.com).
     * Used by the Settings "Test connection" flow when an advertiser key is set.
     *
     * @return array{ok: bool, status?: int|null, body?: mixed, error?: string}
     */
    public function probeAdvertiserKey(Shop $shop): array
    {
        $key = $shop->advertiser_api_key;
        if (! $key) {
            return ['ok' => false, 'error' => 'missing_advertiser_key'];
        }

        $base = rtrim((string) config('ads.advertiser_api_url', 'https://api.ads.openai.com/v1'), '/');

        try {
            $response = Http::withToken($key)
                ->acceptJson()
                ->timeout(15)
                ->get($base.'/ad_account');
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return ['ok' => false, 'status' => null, 'error' => $e->getMessage()];
        }

        return [
            'ok'     => $response->successful(),
            'status' => $response->status(),
            'body'   => $response->json() ?? $response->body(),
        ];
    }

    /**
     * Ensure the Pixel ID is attached as the `pid` query parameter.
     */
    protected function buildUrl(string $baseUrl, string $pixelId): string
    {
        $parts = parse_url($baseUrl) ?: [];
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? 'bzr.openai.com';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '/v1/events';
        if ($path === '' || $path === '/') {
            $path = '/v1/events';
        }

        $query = [];
        if (! empty($parts['query'])) {
            parse_str($parts['query'], $query);
        }
        $query['pid'] = $pixelId;

        return $scheme.'://'.$host.$port.$path.'?'.http_build_query($query);
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function normalizeEvents(array $event): array
    {
        // Already a batch: { events: [...] } (shouldn't happen, but be kind)
        if (isset($event['events']) && is_array($event['events'])) {
            return array_values($event['events']);
        }

        // A list of event objects.
        if (array_is_list($event) && isset($event[0]) && is_array($event[0])) {
            return $event;
        }

        // Single event object (has id/type or event_name leftover).
        if ($event !== []) {
            return [$event];
        }

        return [];
    }
}
