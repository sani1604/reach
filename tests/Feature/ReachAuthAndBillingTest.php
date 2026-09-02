<?php

namespace Tests\Feature;

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReachAuthAndBillingTest extends TestCase
{
    use RefreshDatabase;

    protected Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'shopify.api_key'    => 'test-api-key',
            'shopify.api_secret' => 'test-secret',
        ]);

        $this->shop = Shop::create([
            'shopify_domain' => 'test-store.myshopify.com',
            'access_token'   => 'token',
            'pixel_id'       => 'PX-123',
            'capi_token'     => 'capi-key',
            'installed_at'   => now(),
        ]);
    }

    /* ---------- App Bridge session-token auth ---------- */

    public function test_session_token_authenticates_embedded_request(): void
    {
        $token = $this->sessionToken();

        $this->get('/dashboard', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertSee('Dashboard');
    }

    public function test_invalid_session_token_falls_back_and_redirects(): void
    {
        $this->get('/dashboard', ['Authorization' => 'Bearer invalid.token.here'])
            ->assertRedirect(route('auth.install'));
    }

    public function test_expired_session_token_is_rejected(): void
    {
        // Beyond the 2-minute clock-skew leeway.
        $token = $this->sessionToken(['exp' => time() - 500, 'nbf' => time() - 600]);

        $this->get('/dashboard', ['Authorization' => 'Bearer '.$token])
            ->assertRedirect(route('auth.boot', [
                'shop' => 'test-store.myshopify.com',
                'to'   => '/dashboard',
            ]));
    }

    public function test_id_token_query_param_authenticates_page_navigation(): void
    {
        $this->get('/settings?shop=test-store.myshopify.com&id_token='.$this->sessionToken())
            ->assertOk()
            ->assertSee('Conversions API');
    }

    public function test_token_with_wrong_audience_is_rejected(): void
    {
        $token = $this->sessionToken(['aud' => 'someone-elses-key']);

        $this->get('/dashboard', ['Authorization' => 'Bearer '.$token])
            ->assertRedirect(route('auth.install'));
    }

    /* ---------- CAPI test-connection button ---------- */

    public function test_capi_test_connection_reports_success(): void
    {
        Http::fake([
            'capi.openai.example/*' => Http::response(['events_received' => 1], 200),
        ]);

        $this->actingAsShop()
            ->post('/settings/test', [
                'pixel_id'   => 'PX-123',
                'capi_url'   => 'https://capi.openai.example/v1/events',
                'capi_token' => 'capi-key',
            ])
            ->assertRedirect()
            ->assertSessionHas('test_ok');
    }

    public function test_capi_test_connection_reports_failure(): void
    {
        Http::fake([
            'capi.openai.example/*' => Http::response(['error' => 'unauthorized'], 401),
        ]);

        $this->actingAsShop()
            ->post('/settings/test', [
                'pixel_id'   => 'PX-123',
                'capi_url'   => 'https://capi.openai.example/v1/events',
                'capi_token' => 'capi-key',
            ])
            ->assertRedirect()
            ->assertSessionHas('test_error');
    }

    public function test_capi_test_requires_token(): void
    {
        $this->shop->update(['capi_token' => null]);

        $this->actingAsShop()
            ->post('/settings/test', [
                'pixel_id'   => 'PX-123',
                'capi_url'   => null,
                'capi_token' => null,
            ])
            ->assertRedirect()
            ->assertSessionHas('test_error');
    }

    /* ---------- Live dashboard endpoint ---------- */

    public function test_live_endpoint_returns_counters(): void
    {
        $this->actingAsShop()
            ->get('/dashboard/live', ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonStructure(['today', 'last_hour', 'month', 'net_revenue']);
    }

    public function test_sse_stream_serves_event_stream_headers(): void
    {
        config(['reach.sse.max_seconds' => 1, 'reach.sse.interval' => 0]);

        $response = $this->actingAsShop()->get('/dashboard/stream?after=0');

        $response->assertOk();
        $this->assertStringContainsString('text/event-stream', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('no-cache', $response->headers->get('Cache-Control'));
        $this->assertSame('no', $response->headers->get('X-Accel-Buffering'));
    }

    /* ---------- Growth plan gating ---------- */

    public function test_growth_shop_sees_campaign_report(): void
    {
        $this->shop->update(['plan' => 'growth', 'plan_status' => 'active']);

        $this->actingAsShop()
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Top campaigns');
    }

    public function test_free_shop_does_not_see_campaign_report(): void
    {
        $this->actingAsShop()
            ->get('/dashboard')
            ->assertOk()
            ->assertDontSee('Top campaigns');
    }

    /* ---------- OAuth Install & Callback Flow ---------- */

    public function test_oauth_install_sets_session_state_and_renders_redirect_page(): void
    {
        // The authorize navigation must happen top-level (JS bounce page) —
        // OAuth redirects are blocked inside the admin iframe.
        $response = $this->get('/auth/install?shop=new-store.myshopify.com');

        $response->assertOk()
            ->assertSee('admin/oauth/authorize', false)
            ->assertSee('window.top', false);

        $this->assertNotNull(session('shopify.state'));
        $this->assertEquals('new-store.myshopify.com', session('shopify.shop'));
    }

    public function test_oauth_install_skips_consent_for_installed_shop(): void
    {
        $this->get('/auth/install?shop=test-store.myshopify.com')
            ->assertRedirect(route('dashboard', ['shop' => 'test-store.myshopify.com']));
    }

    public function test_oauth_callback_succeeds_with_valid_state_and_hmac(): void
    {
        Http::fake([
            'test-store.myshopify.com/admin/oauth/access_token' => Http::response([
                'access_token'            => 'shpca_newtoken',
                'scope'                   => 'read_orders,read_products,write_pixels',
                'expires_in'              => 3600,
                'refresh_token'           => 'shpca_refresh',
                'refresh_token_expires_in' => 7776000,
            ], 200),
            'test-store.myshopify.com/admin/api/*/webhooks.json' => Http::response(['webhooks' => []], 200),
            'test-store.myshopify.com/admin/api/*/webhooks' => Http::response([], 201),
            'test-store.myshopify.com/admin/api/*/graphql.json' => Http::response([
                'data' => [
                    'webPixelCreate' => [
                        'userErrors' => [],
                        'webPixel'   => ['id' => 'gid://shopify/WebPixelActivation/42'],
                    ],
                ],
            ], 200),
        ]);

        $state = 'test-state-123';
        $params = [
            'code'      => 'auth-code-xyz',
            'shop'      => 'test-store.myshopify.com',
            'state'     => $state,
            'timestamp' => '1725200000',
        ];
        $params['hmac'] = $this->oauthQueryHmac($params);
        $rawQuery = http_build_query($params);

        $response = $this->withSession(['shopify.state' => $state, 'shopify.shop' => 'test-store.myshopify.com'])
            ->get('/auth/callback?'.$rawQuery);

        // The admin app URL has no sub-path — /apps/{handle}/dashboard does
        // not exist and 404s inside the Shopify admin.
        $response->assertOk()
            ->assertSee('admin/apps/reach-openai-ads-pixel', false)
            ->assertDontSee('/apps/reach/dashboard', false);

        $this->assertDatabaseHas('shops', [
            'shopify_domain' => 'test-store.myshopify.com',
            'access_token'   => 'shpca_newtoken',
            'refresh_token'  => 'shpca_refresh',
            'pixel_id'       => 'gid://shopify/WebPixelActivation/42',
        ]);
    }

    public function test_oauth_callback_rejects_invalid_state(): void
    {
        $state = 'correct-state';
        $params = [
            'code'      => 'auth-code-xyz',
            'shop'      => 'test-store.myshopify.com',
            'state'     => 'wrong-state',
            'timestamp' => '1725200000',
        ];
        $params['hmac'] = $this->oauthQueryHmac($params);
        $rawQuery = http_build_query($params);

        $response = $this->withSession(['shopify.state' => $state, 'shopify.shop' => 'test-store.myshopify.com'])
            ->get('/auth/callback?'.$rawQuery);

        $response->assertStatus(403);
    }

    public function test_oauth_callback_rejects_invalid_hmac(): void
    {
        $state = 'test-state-123';
        $rawQuery = 'code=auth-code-xyz&shop=test-store.myshopify.com&state=' . $state . '&timestamp=1725200000&hmac=bogus';

        $response = $this->withSession(['shopify.state' => $state, 'shopify.shop' => 'test-store.myshopify.com'])
            ->get('/auth/callback?'.$rawQuery);

        $response->assertStatus(403);
    }

    /* ---------- Embedded boot + token exchange (managed installation) ---------- */

    public function test_embedded_boot_renders_for_installed_shop(): void
    {
        $this->get('/?shop=test-store.myshopify.com&host='.base64_encode('admin.shopify.com/store/test-store'))
            ->assertOk()
            ->assertSee('app-bridge.js', false)
            ->assertSee('token-exchange', false);
    }

    public function test_token_exchange_rejects_invalid_token(): void
    {
        $this->postJson('/auth/token-exchange', [
            'shop' => 'test-store.myshopify.com',
        ], ['Authorization' => 'Bearer not-a-jwt'])
            ->assertStatus(401)
            ->assertJson(['ok' => false, 'reason' => 'invalid_token']);
    }

    public function test_token_exchange_rejects_shop_mismatch(): void
    {
        // Token is valid but belongs to a different store.
        $this->postJson('/auth/token-exchange', [
            'shop' => 'other-store.myshopify.com',
        ], ['Authorization' => 'Bearer '.$this->sessionToken()])
            ->assertStatus(401);
    }

    public function test_token_exchange_reports_not_installed_for_unknown_shop(): void
    {
        Http::fake([
            'fresh-store.myshopify.com/admin/oauth/access_token' => Http::response(['error' => 'not_installed'], 400),
        ]);

        $token = $this->sessionToken([], 'fresh-store.myshopify.com');

        $this->postJson('/auth/token-exchange', [
            'shop' => 'fresh-store.myshopify.com',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJson(['ok' => false, 'reason' => 'not_installed']);
    }

    public function test_token_exchange_installs_shop_with_expiring_tokens(): void
    {
        Http::fake([
            'fresh-store.myshopify.com/admin/oauth/access_token' => Http::response([
                'access_token'             => 'shpca_exchanged',
                'scope'                    => 'read_orders,read_products,write_pixels',
                'expires_in'               => 3600,
                'refresh_token'            => 'shpca_refresh_exchanged',
                'refresh_token_expires_in' => 7776000,
            ], 200),
            'fresh-store.myshopify.com/admin/api/*/webhooks.json' => Http::response(['webhooks' => []], 200),
            'fresh-store.myshopify.com/admin/api/*/webhooks' => Http::response([], 201),
            'fresh-store.myshopify.com/admin/api/*/graphql.json' => Http::response([
                'data' => [
                    'webPixelCreate' => [
                        'userErrors' => [],
                        'webPixel'   => ['id' => 'gid://shopify/WebPixelActivation/7'],
                    ],
                ],
            ], 200),
        ]);

        $token = $this->sessionToken([], 'fresh-store.myshopify.com');

        $this->postJson('/auth/token-exchange', [
            'shop' => 'fresh-store.myshopify.com',
        ], ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('shops', [
            'shopify_domain' => 'fresh-store.myshopify.com',
            'access_token'   => 'shpca_exchanged',
            'refresh_token'  => 'shpca_refresh_exchanged',
            'pixel_id'       => 'gid://shopify/WebPixelActivation/7',
        ]);

        $shop = Shop::where('shopify_domain', 'fresh-store.myshopify.com')->first();
        $this->assertNotNull($shop->token_expires_at);
        $this->assertTrue($shop->token_expires_at->isFuture());
    }

    /* ---------- helpers ---------- */

    protected function oauthQueryHmac(array $params): string
    {
        unset($params['hmac'], $params['signature']);
        ksort($params);
        return hash_hmac('sha256', http_build_query($params), 'test-secret');
    }

    protected function actingAsShop()
    {
        return $this->withSession(['shop' => $this->shop->shopify_domain]);
    }

    protected function sessionToken(array $overrides = [], ?string $shop = null): string
    {
        $shop = $shop ?: 'test-store.myshopify.com';

        $header = $this->b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = $this->b64(json_encode(array_merge([
            'iss'  => 'https://'.$shop.'/admin',
            'dest' => 'https://'.$shop,
            'aud'  => 'test-api-key',
            'sub'  => '1',
            'exp'  => time() + 3600,
            'nbf'  => time() - 10,
            'iat'  => time(),
            'jti'  => 'abc123',
        ], $overrides)));

        $signature = $this->b64(hash_hmac('sha256', "{$header}.{$payload}", 'test-secret', true));

        return "{$header}.{$payload}.{$signature}";
    }

    protected function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
