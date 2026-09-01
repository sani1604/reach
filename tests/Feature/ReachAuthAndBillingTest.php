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
        $token = $this->sessionToken(['exp' => time() - 100, 'nbf' => time() - 200]);

        $this->get('/dashboard', ['Authorization' => 'Bearer '.$token])
            ->assertRedirect(route('auth.install'));
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

    public function test_oauth_install_sets_session_state_and_redirects(): void
    {
        $response = $this->get('/auth/install?shop=test-store.myshopify.com');

        $response->assertRedirect();
        $this->assertNotNull(session('shopify.state'));
        $this->assertEquals('test-store.myshopify.com', session('shopify.shop'));
    }

    public function test_oauth_callback_succeeds_with_valid_state_and_hmac(): void
    {
        Http::fake([
            'test-store.myshopify.com/admin/oauth/access_token' => Http::response(['access_token' => 'shpca_newtoken'], 200),
            'test-store.myshopify.com/admin/api/*/webhooks.json' => Http::response(['webhooks' => []], 200),
            'test-store.myshopify.com/admin/api/*/webhooks' => Http::response([], 201),
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

        $response->assertRedirect('https://test-store.myshopify.com/admin/apps/reach');
        $this->assertDatabaseHas('shops', [
            'shopify_domain' => 'test-store.myshopify.com',
            'access_token'   => 'shpca_newtoken',
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

    protected function sessionToken(array $overrides = []): string
    {
        $header = $this->b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = $this->b64(json_encode(array_merge([
            'iss'  => 'https://test-store.myshopify.com/admin',
            'dest' => 'https://test-store.myshopify.com',
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
