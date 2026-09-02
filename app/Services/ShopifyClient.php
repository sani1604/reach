<?php

namespace App\Services;

use App\Models\Shop;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

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

    /**
     * Keep the stored offline token current. Under the 2026 expiring-token
     * policy every public app must refresh via the refresh_token grant.
     */
    public function ensureFreshToken(Shop $shop): void
    {
        if (! $shop->tokenNeedsRefresh() || ! $shop->refreshTokenUsable()) {
            return;
        }

        // Prevent concurrent workers from refreshing the same store twice
        // (rotated refresh tokens invalidate their predecessor once used).
        $lock = Cache::lock('shopify:refresh-token:'.$shop->id, 15);

        try {
            if (! $lock->get()) {
                return;
            }

            $shop->refresh();

            if (! $shop->tokenNeedsRefresh() || ! $shop->refreshTokenUsable()) {
                return;
            }

            $this->refreshOfflineToken($shop);
        } catch (Throwable $e) {
            logger()->warning('Shopify token refresh failed', [
                'shop' => $shop->shopify_domain,
                'error' => $e->getMessage(),
            ]);
        } finally {
            try {
                $lock->release();
            } catch (Throwable) {
                // Lock may have expired — not fatal.
            }
        }
    }

    /**
     * refresh_token grant — rotates both the access and the refresh token.
     */
    public function refreshOfflineToken(Shop $shop): bool
    {
        if (! $shop->refresh_token) {
            return false;
        }

        $res = Http::asForm()->acceptJson()->timeout(30)->post(
            "https://{$shop->shopify_domain}/admin/oauth/access_token",
            [
                'client_id'     => config('shopify.api_key'),
                'client_secret' => config('shopify.api_secret'),
                'grant_type'    => 'refresh_token',
                'refresh_token' => $shop->refresh_token,
            ]
        );

        $body = $res->json() ?? [];

        if (empty($body['access_token'])) {
            logger()->warning('Shopify refresh_token grant failed', [
                'shop'   => $shop->shopify_domain,
                'status' => $res->status(),
                'error'  => $body['error'] ?? null,
            ]);

            return false;
        }

        $shop->fill($this->tokenAttributes($body))->save();

        return true;
    }

    /**
     * Map an OAuth / token-exchange response body onto Shop attributes.
     */
    public function tokenAttributes(array $body): array
    {
        $attrs = ['access_token' => $body['access_token']];

        if (! empty($body['scope'])) {
            $attrs['token_scopes'] = $body['scope'];
        }

        // 2026 expiring-token response (new public apps). Legacy responses
        // omit expires_in / refresh_token entirely.
        if (! empty($body['expires_in'])) {
            $attrs['token_expires_at'] = Carbon::now()->addSeconds((int) $body['expires_in']);
        }

        if (! empty($body['refresh_token'])) {
            $attrs['refresh_token'] = $body['refresh_token'];
            $attrs['refresh_token_expires_at'] = Carbon::now()->addSeconds(
                (int) ($body['refresh_token_expires_in'] ?? 7776000) // 90 days
            );
        }

        return $attrs;
    }

    /*
    |------------------------------------------------------------------
    | Admin API (REST — legacy but still supported for these endpoints)
    |------------------------------------------------------------------
    */

    public function get(Shop $shop, string $path, array $query = []): Response
    {
        $this->ensureFreshToken($shop);

        return Http::withHeaders($this->headers($shop))
            ->timeout(30)
            ->retry(2, 200)
            ->get($this->baseUrl($shop).$path, $query);
    }

    public function post(Shop $shop, string $path, array $data = []): Response
    {
        $this->ensureFreshToken($shop);

        return Http::withHeaders($this->headers($shop))
            ->timeout(30)
            ->retry(2, 200)
            ->post($this->baseUrl($shop).$path, $data);
    }

    public function delete(Shop $shop, string $path): Response
    {
        $this->ensureFreshToken($shop);

        return Http::withHeaders($this->headers($shop))
            ->timeout(30)
            ->delete($this->baseUrl($shop).$path);
    }

    /**
     * GraphQL Admin API — the primary API for new public apps (REST is
     * legacy). Automatically refreshes an expired offline token first.
     */
    public function graphql(Shop $shop, string $query, array $variables = []): array
    {
        $this->ensureFreshToken($shop);

        $res = Http::withHeaders($this->headers($shop))
            ->timeout(30)
            ->post($this->baseUrl($shop).'/graphql.json', [
                'query'     => $query,
                'variables' => $variables ?: (object) [],
            ]);

        return $res->json() ?? [];
    }

    public function getShop(Shop $shop): array
    {
        return $this->get($shop, '/shop.json')->json('shop', []);
    }

    /**
     * Ensure the required webhooks exist. Idempotent — skips topics already
     * subscribed so reinstalls don't duplicate.
     *
     * NOTE: with Shopify-managed app setup the webhook subscriptions live in
     * shopify.app.toml ([[webhooks.subscriptions]]) and are registered by
     * `shopify app deploy`. This runtime fallback only matters for apps that
     * were configured manually in the Partner Dashboard.
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

    /*
    |------------------------------------------------------------------
    | Web pixel (Customer Events) lifecycle
    |------------------------------------------------------------------
    */

    /**
     * Deployed extensions are NOT active per store until the app creates a
     * web pixel. This is what makes the Reach Pixel actually appear in
     * Customer Events and start tracking. Idempotent.
     */
    public function ensureWebPixel(Shop $shop): ?string
    {
        // The `config` setting (declared in the extension toml) carries the
        // app URL and the shop domain so the sandboxed pixel knows where to
        // POST events — it has no access to the merchant page's origin data.
        $settings = json_encode([
            'config' => json_encode([
                'app_url' => rtrim(config('app.url'), '/'),
                'shop'    => $shop->shopify_domain,
            ], JSON_UNESCAPED_SLASHES),
        ], JSON_UNESCAPED_SLASHES);

        if ($shop->pixel_id) {
            // Keep the recorded pixel's settings fresh.
            $result = $this->graphql($shop, <<<'GRAPHQL'
            mutation WebPixelUpdate($webPixel: WebPixelInput!) {
              webPixelUpdate(webPixel: $webPixel) {
                userErrors { field message }
                webPixel { id }
              }
            }
            GRAPHQL, ['webPixel' => ['settings' => $settings]]);

            if (! $this->hasUserErrors($result)) {
                return $shop->pixel_id;
            }

            // Stale pixel_id (merchant deleted the pixel in the admin) —
            // fall through and create a fresh one.
            $shop->pixel_id = null;
        }

        $result = $this->graphql($shop, <<<'GRAPHQL'
        mutation WebPixelCreate($webPixel: WebPixelInput!) {
          webPixelCreate(webPixel: $webPixel) {
            userErrors { field message }
            webPixel { id }
          }
        }
        GRAPHQL, ['webPixel' => ['settings' => $settings]]);

        if ($this->hasUserErrors($result)) {
            logger()->warning('webPixelCreate failed', [
                'shop'   => $shop->shopify_domain,
                'errors' => $result['data']['webPixelCreate']['userErrors'] ?? $result['errors'] ?? [],
            ]);

            return null;
        }

        $pixelId = $result['data']['webPixelCreate']['webPixel']['id'] ?? null;

        if ($pixelId) {
            $shop->update(['pixel_id' => $pixelId]);
        }

        return $pixelId;
    }

    protected function hasUserErrors(array $result): bool
    {
        foreach ($result['data'] ?? [] as $payload) {
            if (! empty($payload['userErrors'])) {
                return true;
            }
        }

        return array_key_exists('errors', $result);
    }

    /*
    |------------------------------------------------------------------
    | Billing (legacy REST Billing API flow)
    |------------------------------------------------------------------
    */

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
     * Active app subscriptions — works for both the legacy Billing API and
     * 2026 Shopify App Pricing (managed pricing) subscriptions.
     */
    public function activeSubscriptions(Shop $shop): array
    {
        $result = $this->graphql($shop, <<<'GRAPHQL'
        query CurrentAppInstallation {
          currentAppInstallation {
            activeSubscriptions(first: 10) {
              nodes { id name status test trialDays }
            }
          }
        }
        GRAPHQL);

        return $result['data']['currentAppInstallation']['activeSubscriptions']['nodes'] ?? [];
    }

    /*
    |------------------------------------------------------------------
    | OAuth (static helpers — no access token yet)
    |------------------------------------------------------------------
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

    /**
     * Authorization code grant — kept for stores entering through the
     * public install form / non-managed installs. Requests an expiring
     * offline token per the 2026 policy (expiring=1).
     */
    public static function exchangeToken(string $shopDomain, string $code): ?array
    {
        $res = Http::asForm()->acceptJson()->timeout(30)->post(
            "https://{$shopDomain}/admin/oauth/access_token",
            [
                'client_id'     => config('shopify.api_key'),
                'client_secret' => config('shopify.api_secret'),
                'code'          => $code,
                'expiring'      => 1,
            ]
        );

        $body = $res->json() ?? [];

        return isset($body['access_token']) ? $body : null;
    }

    /**
     * Token exchange grant — the 2026 flow for embedded apps using Shopify
     * managed installation. Swaps an App Bridge session (ID) token for an
     * expiring offline access token. No redirects, no install screen.
     */
    public static function exchangeIdToken(string $shopDomain, string $idToken): ?array
    {
        $res = Http::asForm()->acceptJson()->timeout(30)->post(
            "https://{$shopDomain}/admin/oauth/access_token",
            [
                'client_id'           => config('shopify.api_key'),
                'client_secret'       => config('shopify.api_secret'),
                'grant_type'          => 'urn:ietf:params:oauth:grant-type:token-exchange',
                'subject_token'       => $idToken,
                'subject_token_type'  => 'urn:ietf:params:oauth:token-type:id_token',
                'requested_token_type' => 'urn:shopify:params:oauth:token-type:offline-access-token',
                'expiring'            => 1,
            ]
        );

        $body = $res->json() ?? [];

        return isset($body['access_token']) ? $body : null;
    }
}
