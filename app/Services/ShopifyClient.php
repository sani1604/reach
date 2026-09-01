<?php

namespace App\Services;

use App\Models\Shop;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class ShopifyClient
{
    public function baseUrl(Shop $shop): string
    {
        return "https://{$shop->shopify_domain}/admin/api/".config('shopify.api_version');
    }

    protected function headers(Shop $shop): array
    {
        return [
            'X-Shopify-Access-Token' => $shop->access_token,
            'Content-Type'           => 'application/json',
            'Accept'                 => 'application/json',
        ];
    }

    public function get(Shop $shop, string $path, array $query = []): Response
    {
        return Http::withHeaders($this->headers($shop))
            ->timeout(30)
            ->retry(2, 200)
            ->get($this->baseUrl($shop).$path, $query);
    }

    public function post(Shop $shop, string $path, array $data = []): Response
    {
        return Http::withHeaders($this->headers($shop))
            ->timeout(30)
            ->retry(2, 200)
            ->post($this->baseUrl($shop).$path, $data);
    }

    public function delete(Shop $shop, string $path): Response
    {
        return Http::withHeaders($this->headers($shop))
            ->timeout(30)
            ->delete($this->baseUrl($shop).$path);
    }

    public function getShop(Shop $shop): array
    {
        return $this->get($shop, '/shop.json')->json('shop', []);
    }

    /**
     * Ensure the required webhooks exist. Idempotent — skips topics already
     * subscribed so reinstalls don't duplicate.
     */
    public function subscribeWebhooks(Shop $shop): int
    {
        $existing = collect($this->get($shop, '/webhooks.json')->json('webhooks', []))
            ->pluck('topic')->all();

        $created = 0;
        foreach (config('shopify.webhooks', []) as $topic) {
            if (in_array($topic, $existing, true)) {
                continue;
            }
            $this->post($shop, '/webhooks.json', [
                'webhook' => [
                    'topic'   => $topic,
                    'address' => route('webhooks'),
                    'format'  => 'json',
                ],
            ]);
            $created++;
        }

        return $created;
    }

    public function createRecurringCharge(Shop $shop, string $plan): array
    {
        $cfg = config('ads.plans.'.$plan, []);

        return $this->post($shop, '/recurring_application_charges.json', [
            'recurring_application_charge' => [
                'name'       => 'Reach '.ucfirst($plan),
                'price'      => $cfg['price'],
                'currency'   => $cfg['currency'],
                'trial_days' => $cfg['trial_days'] ?? 0,
                'test'       => app()->environment('local'),
                'return_url' => route('billing.confirm'),
            ],
        ])->json('recurring_application_charge', []);
    }

    public function getCharge(Shop $shop, string $chargeId): array
    {
        return $this->get($shop, "/recurring_application_charges/{$chargeId}.json")
            ->json('recurring_application_charge', []);
    }

    /**
     * Static helpers for the OAuth dance (no access token yet).
     */
    public static function authorizeUrl(string $shopDomain, string $state, string $redirectUri): string
    {
        $query = http_build_query([
            'client_id'     => config('shopify.api_key'),
            'scope'         => implode(',', config('shopify.scopes')),
            'redirect_uri'  => $redirectUri,
            'state'         => $state,
        ]);

        return "https://{$shopDomain}/admin/oauth/authorize?".$query;
    }

    public static function exchangeToken(string $shopDomain, string $code): ?string
    {
        $res = Http::timeout(30)->post("https://{$shopDomain}/admin/oauth/access_token", [
            'client_id'     => config('shopify.api_key'),
            'client_secret' => config('shopify.api_secret'),
            'code'          => $code,
        ]);

        return $res->json('access_token');
    }
}
