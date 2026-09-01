<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Shop;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReachEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    protected Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shopify.api_secret' => 'test-secret']);

        $this->shop = Shop::create([
            'shopify_domain' => 'test-store.myshopify.com',
            'access_token'   => 'token',
            'pixel_id'       => 'PX-123',
            'capi_token'     => 'capi-key',
            'installed_at'   => now(),
        ]);
    }

    public function test_track_upserts_visitor_with_click_ids(): void
    {
        $this->postJson('/api/track', [
            'shop'       => 'test-store.myshopify.com',
            'vid'        => 'visitor-abc',
            'event_name' => 'product_added_to_cart',
            'user_data'  => ['fbc' => 'fb.1.abc', 'fbp' => 'fb.1.def'],
            'data'       => ['value' => 899, 'currency' => 'INR'],
        ])->assertStatus(202);

        $this->assertDatabaseHas('visitors', [
            'shop_id' => $this->shop->id,
            'vid'     => 'visitor-abc',
            'fbc'     => 'fb.1.abc',
            'fbp'     => 'fb.1.def',
        ]);
    }

    public function test_enrich_joins_click_ids_to_recorded_purchase(): void
    {
        // A Purchase already exists (from an order webhook).
        Event::create([
            'shop_id'     => $this->shop->id,
            'event_name'  => 'Purchase',
            'event_id'    => 'purchase-42',
            'dedup_key'   => 'purchase:42',
            'source'      => 'server',
            'order_id'    => '42',
            'currency'    => 'INR',
            'value'       => 1499.00,
            'occurred_at' => now(),
            'payload'     => ['user_data' => ['email' => 'buyer@example.com']],
        ]);

        $this->postJson('/api/enrich', [
            'shop' => 'test-store.myshopify.com',
            'vid'  => 'visitor-abc',
            'data' => [
                'order_id' => '42',
                'fbc'      => 'fb.1.abc',
                'fbp'      => 'fb.1.def',
            ],
        ])->assertOk()->assertJson(['enriched' => true]);

        $purchase = Event::where('order_id', '42')->where('event_name', 'Purchase')->first();
        $this->assertEquals('fb.1.abc', $purchase->payload['user_data']['fbc']);
        $this->assertEquals('fb.1.def', $purchase->payload['user_data']['fbp']);
    }

    public function test_enrich_before_order_webhook_stores_order_on_visitor(): void
    {
        // Enrichment arrives before the order webhook — no Purchase yet.
        $this->postJson('/api/enrich', [
            'shop' => 'test-store.myshopify.com',
            'vid'  => 'visitor-abc',
            'data' => [
                'order_id' => '77',
                'fbc'      => 'fb.1.abc',
            ],
        ])->assertOk()->assertJson(['enriched' => false]);

        $this->assertDatabaseHas('visitors', [
            'shop_id'  => $this->shop->id,
            'vid'      => 'visitor-abc',
            'order_id' => '77',
            'fbc'      => 'fb.1.abc',
        ]);
    }

    public function test_order_webhook_joins_click_ids_by_email(): void
    {
        // A visitor with a click id was captured earlier.
        Visitor::create([
            'shop_id'      => $this->shop->id,
            'vid'          => 'visitor-abc',
            'email'        => 'buyer@example.com',
            'fbc'          => 'fb.1.abc',
            'last_seen_at' => now(),
        ]);

        $body = json_encode([
            'id'          => 42,
            'currency'    => 'INR',
            'total_price' => '1499.00',
            'line_items'  => [],
            'customer'    => ['email' => 'buyer@example.com'],
        ]);

        $this->postJson('/webhooks', json_decode($body, true), [
            'X-Shopify-Topic'       => 'orders/create',
            'X-Shopify-Shop-Domain' => 'test-store.myshopify.com',
            'X-Shopify-Hmac-Sha256' => $this->hmac($body),
        ])->assertOk();

        $purchase = Event::where('order_id', '42')->where('event_name', 'Purchase')->first();
        $this->assertEquals('fb.1.abc', $purchase->payload['user_data']['fbc']);
    }

    protected function hmac(string $body): string
    {
        return base64_encode(hash_hmac('sha256', $body, 'test-secret', true));
    }
}
