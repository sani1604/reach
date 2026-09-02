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

        // OpenAI Ads CAPI uses the pixel ID as a query parameter and the
        // event schema differs from the older Meta-style draft used here.
        $url = rtrim($url, '/');
        $separator = str_contains($url, '?') ? '&' : '?';
        $url .= $separator.'pid='.rawurlencode((string) $shop->pixel_id);

        $payload = [
            'validate_only' => false,
            'integration_source' => 'reach_shopify',
            'events' => [$this->openAiEvent($event)],
        ];

        try {
            $response = Http::withToken($token)
                ->acceptJson()
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

    protected function openAiEvent(array $event): array
    {
        $type = [
            'PageView' => 'page_viewed',
            'ViewContent' => 'contents_viewed',
            'AddToCart' => 'items_added',
            'InitiateCheckout' => 'checkout_started',
            'Purchase' => 'order_created',
        ][$event['event_name'] ?? ''] ?? 'custom';

        $custom = $event['custom_data'] ?? [];
        $data = ['type' => $type === 'custom' ? 'customer_action' : 'contents'];
        if (isset($custom['value'])) {
            // OpenAI expects the amount in the currency's minor unit.
            $data['amount'] = (int) round(((float) $custom['value']) * 100);
        }
        if (! empty($custom['currency'])) {
            $data['currency'] = strtoupper((string) $custom['currency']);
        }
        if (! empty($custom['content_ids'])) {
            $data['contents'] = array_map(fn ($id) => ['id' => (string) $id], (array) $custom['content_ids']);
        }

        $result = [
            'id' => (string) ($event['event_id'] ?? uniqid('reach_', true)),
            'type' => $type,
            'timestamp_ms' => ((int) ($event['event_time'] ?? time())) * 1000,
            'action_source' => 'web',
            'data' => $data,
        ];

        if ($type === 'custom') {
            $result['custom_event_name'] = 'reach_test_event';
        }
        if (! empty($event['user_data'])) {
            $result['user'] = $event['user_data'];
        }
        return $result;
    }
}
