<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Services\ShopifyApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReachMultiAppTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'reach.app' => 'reach',
            'shopify.api_key' => 'reach-client-id',
            'shopify.api_secret' => 'reach-secret',
            'reach.apps.reach.handle' => 'reach-openai-ads-pixel',
            'reach.apps.pixelai.handle' => 'pixelai-chatgpt-openai-ads',
        ]);
        putenv('SHOPIFY_REACH_API_KEY=reach-client-id');
        putenv('SHOPIFY_REACH_API_SECRET=reach-secret');
        putenv('SHOPIFY_PIXELAI_API_KEY=pixelai-client-id');
        putenv('SHOPIFY_PIXELAI_API_SECRET=pixelai-secret');
        $_ENV['SHOPIFY_REACH_API_KEY'] = 'reach-client-id';
        $_ENV['SHOPIFY_REACH_API_SECRET'] = 'reach-secret';
        $_ENV['SHOPIFY_PIXELAI_API_KEY'] = 'pixelai-client-id';
        $_ENV['SHOPIFY_PIXELAI_API_SECRET'] = 'pixelai-secret';
    }

    public function test_same_store_can_install_both_apps(): void
    {
        $domain = 'dual-store.myshopify.com';

        $reach = Shop::upsertForApp($domain, [
            'access_token' => 'reach-token',
            'installed_at' => now(),
            'pixel_id'     => 'PX-REACH',
            'capi_token'   => 'capi-reach',
        ], 'reach');

        $pixelai = Shop::upsertForApp($domain, [
            'access_token' => 'pixelai-token',
            'installed_at' => now(),
            'pixel_id'     => 'PX-PIXELAI',
            'capi_token'   => 'capi-pixelai',
        ], 'pixelai');

        $this->assertNotSame($reach->id, $pixelai->id);
        $this->assertSame('reach', $reach->app_key);
        $this->assertSame('pixelai', $pixelai->app_key);
        $this->assertSame('reach-token', $reach->fresh()->access_token);
        $this->assertSame('pixelai-token', $pixelai->fresh()->access_token);

        ShopifyApp::setKey('reach');
        $this->assertSame($reach->id, Shop::findForApp($domain)->id);

        ShopifyApp::setKey('pixelai');
        $this->assertSame($pixelai->id, Shop::findForApp($domain)->id);
    }

    public function test_branding_switches_with_app_key(): void
    {
        ShopifyApp::setKey('reach');
        $this->assertSame('Reach', ShopifyApp::name());
        $this->assertSame('R', ShopifyApp::mark());

        ShopifyApp::setKey('pixelai');
        $this->assertSame('PixelAI', ShopifyApp::name());
        $this->assertSame('P', ShopifyApp::mark());
        $this->assertStringContainsString('OpenAI', ShopifyApp::fullName());
    }

    public function test_session_token_binds_matching_app(): void
    {
        $token = $this->jwt(
            aud: 'pixelai-client-id',
            secret: 'pixelai-secret',
            dest: 'https://dual-store.myshopify.com'
        );

        Shop::upsertForApp('dual-store.myshopify.com', [
            'access_token' => 't',
            'installed_at' => now(),
        ], 'pixelai');

        $this->get('/dashboard', ['Authorization' => 'Bearer '.$token])
            ->assertOk();

        $this->assertSame('pixelai', ShopifyApp::key());
    }

    public function test_landing_renders_active_brand(): void
    {
        config(['reach.app' => 'pixelai']);
        ShopifyApp::setKey('pixelai');

        $this->get('/landing')
            ->assertOk()
            ->assertSee('PixelAI', false);
    }

    protected function jwt(string $aud, string $secret, string $dest): string
    {
        $header = $this->b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = $this->b64(json_encode([
            'iss' => $dest.'/admin',
            'dest'=> $dest,
            'aud' => $aud,
            'sub' => '1',
            'exp' => time() + 60,
            'nbf' => time() - 10,
            'iat' => time() - 10,
            'jti' => 'jti',
            'sid' => 'sid',
        ]));
        $sig = $this->b64(hash_hmac('sha256', "{$header}.{$payload}", $secret, true));

        return "{$header}.{$payload}.{$sig}";
    }

    protected function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
