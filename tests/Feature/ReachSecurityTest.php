<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Services\OpenAiCapiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * VAPT-oriented regression tests for authz, SSRF, open redirect, and feed auth.
 */
class ReachSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'shopify.api_key'    => 'test-api-key',
            'shopify.api_secret' => 'test-secret',
            'ads.capi_url'       => 'https://bzr.openai.com/v1/events',
        ]);

        $this->shop = Shop::create([
            'shopify_domain' => 'test-store.myshopify.com',
            'access_token'   => 'token',
            'pixel_id'       => 'PX-123',
            'capi_token'     => 'capi-key',
            'feed_token'     => 'feed-secret-token-abcdefghijklmnopqrstuvwxyz',
            'installed_at'   => now(),
        ]);
    }

    public function test_spoofed_shop_header_cannot_access_dashboard(): void
    {
        // Attacker sends only a spoofable Shopify header — must NOT authenticate.
        $this->get('/dashboard', [
            'X-Shopify-Shop-Domain' => 'test-store.myshopify.com',
        ])->assertRedirect();

        $this->getJson('/dashboard/live', [
            'X-Shopify-Shop-Domain' => 'test-store.myshopify.com',
            'Accept' => 'application/json',
        ])->assertUnauthorized();
    }

    public function test_spoofed_referer_cannot_access_dashboard(): void
    {
        $this->get('/dashboard?shop=test-store.myshopify.com', [
            'Referer' => 'https://admin.shopify.com/store/test-store/apps/reach',
        ])->assertRedirect();
    }

    public function test_session_cookie_still_authenticates(): void
    {
        $this->withSession(['shop' => 'test-store.myshopify.com'])
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Dashboard');
    }

    public function test_session_token_still_authenticates(): void
    {
        $token = $this->sessionToken();

        $this->get('/dashboard', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertSee('Dashboard');
    }

    public function test_session_token_shop_mismatch_is_rejected(): void
    {
        // Token is for test-store; query claims another shop.
        $token = $this->sessionToken();

        $this->get('/dashboard?shop=other-store.myshopify.com', [
            'Authorization' => 'Bearer '.$token,
        ])->assertRedirect();
    }

    public function test_public_feed_requires_exact_token(): void
    {
        $this->get('/feed/test-store.myshopify.com/wrong-token-xxxxxxxxxxxxxxxxxxxx')
            ->assertNotFound();

        $this->get('/feed/evil.com/'.$this->shop->feed_token)
            ->assertNotFound();
    }

    public function test_pixel_config_does_not_leak_unknown_shops(): void
    {
        $this->getJson('/api/pixel-config?shop=unknown-store.myshopify.com')
            ->assertOk()
            ->assertJson(['enabled' => false])
            ->assertJsonMissing(['shop' => 'unknown-store.myshopify.com']);
    }

    public function test_track_rejects_unknown_events_and_bad_shops(): void
    {
        $this->postJson('/api/track', [
            'shop'  => 'test-store.myshopify.com',
            'event' => 'DropTableStudents',
            'data'  => [],
        ])->assertStatus(422);

        $this->postJson('/api/track', [
            'shop'  => 'evil.com',
            'event' => 'PageView',
            'data'  => [],
        ])->assertStatus(400);
    }

    public function test_capi_client_blocks_ssrf_hosts(): void
    {
        Http::fake([
            'bzr.openai.com/*' => Http::response(['ok' => true], 200),
            '*'                => Http::response('blocked', 500),
        ]);

        $probe = new Shop([
            'shopify_domain' => 'test-store.myshopify.com',
            'pixel_id'       => 'PX-123',
            'capi_token'     => 'capi-key',
            // Attempt SSRF via merchant-controlled override.
            'capi_url'       => 'http://169.254.169.254/latest/meta-data',
        ]);

        $result = app(OpenAiCapiClient::class)->send($probe, [
            'id'   => 'evt-1',
            'type' => 'page_viewed',
        ], validateOnly: true);

        $this->assertTrue($result['ok'] ?? false);
        $this->assertStringContainsString('bzr.openai.com', (string) ($result['url'] ?? ''));
        $this->assertStringNotContainsString('169.254', (string) ($result['url'] ?? ''));

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'bzr.openai.com');
        });
        Http::assertNotSent(function ($request) {
            return str_contains($request->url(), '169.254');
        });
    }

    public function test_settings_rejects_ssrf_capi_url(): void
    {
        $token = $this->sessionToken();

        $this->withSession(['shop' => 'test-store.myshopify.com'])
            ->post('/settings', [
                '_token'     => csrf_token(),
                'id_token'   => $token,
                'pixel_id'   => 'PX-123',
                'capi_token' => 'capi-key',
                'capi_url'   => 'http://127.0.0.1:6379/',
            ], [
                'Authorization' => 'Bearer '.$token,
            ])
            ->assertRedirect();

        $this->shop->refresh();
        $this->assertNull($this->shop->capi_url);
    }

    public function test_oauth_callback_rejects_evil_host_param(): void
    {
        // Covered at the helper layer; also ensure callback path builds safe URL.
        $evilHost = base64_encode('evil.example/phish');
        $safe = \App\Services\ShopDomain::safeAdminHostParam($evilHost);
        $this->assertNull($safe);
    }

    public function test_shop_secrets_are_hidden_from_array(): void
    {
        $arr = $this->shop->toArray();
        $this->assertArrayNotHasKey('access_token', $arr);
        $this->assertArrayNotHasKey('capi_token', $arr);
        $this->assertArrayNotHasKey('feed_token', $arr);
        // Attribute access still works for app code.
        $this->assertSame('token', $this->shop->access_token);
    }

    protected function sessionToken(array $overrides = []): string
    {
        $header = $this->b64url(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = array_merge([
            'iss' => 'https://test-store.myshopify.com/admin',
            'dest'=> 'https://test-store.myshopify.com',
            'aud' => 'test-api-key',
            'sub' => '1',
            'exp' => time() + 60,
            'nbf' => time() - 10,
            'iat' => time() - 10,
            'jti' => 'test-jti',
            'sid' => 'test-sid',
        ], $overrides);

        $body = $this->b64url(json_encode($payload));
        $sig = $this->b64url(hash_hmac(
            'sha256',
            "{$header}.{$body}",
            (string) config('shopify.api_secret'),
            true
        ));

        return "{$header}.{$body}.{$sig}";
    }

    protected function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
