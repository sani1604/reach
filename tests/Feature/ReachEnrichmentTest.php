<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Shop;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReachEnrichmentTest extends TestCase
{
    use RefreshDatabase;

    protected Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();
        config(['shopify.api_secret' => 'test-secret']);
        Http::fake([
            'bzr.openai.com/*' => Http::response(['ok' => true], 200),
            '*'                => Http::response(['ok' => true], 200),
        ]);

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

    public function test_order_webhook_tags_cod_and_hashes_india_phone(): void
    {
        $body = json_encode([
            'id'                    => 88,
            'name'                  => '#1088',
            'currency'              => 'INR',
            'total_price'           => '2499.00',
            'financial_status'      => 'pending',
            'payment_gateway_names' => ['Cash on Delivery (COD)'],
            'line_items'            => [
                ['product_id' => 1, 'title' => 'Kurta', 'price' => '2499.00', 'quantity' => 1],
            ],
            'customer'              => ['phone' => '9876543210'],
            'billing_address'       => [
                'phone'        => '9876543210',
                'city'         => 'Mumbai',
                'province'     => 'MH',
                'zip'          => '400001',
                'country_code' => 'IN',
            ],
        ]);

        $this->postJson('/webhooks', json_decode($body, true), [
            'X-Shopify-Topic'       => 'orders/create',
            'X-Shopify-Shop-Domain' => 'test-store.myshopify.com',
            'X-Shopify-Hmac-Sha256' => $this->hmac($body),
            'X-Shopify-Webhook-Id'  => 'wh-cod-1',
        ])->assertOk();

        $purchase = Event::where('order_id', '88')->where('event_name', 'Purchase')->first();
        $this->assertNotNull($purchase);
        $this->assertTrue((bool) ($purchase->payload['is_cod'] ?? false));
        $this->assertSame('cod', $purchase->payload['payment_method'] ?? null);
        $this->assertSame('9876543210', $purchase->payload['user_data']['phone'] ?? null);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'pid=')) {
                return false;
            }
            $events = $request['events'] ?? [];
            $user = $events[0]['user'] ?? [];
            $expected = hash('sha256', '919876543210');

            return ($user['phone_numbers_sha256'][0] ?? null) === $expected
                && ($user['cities'][0] ?? null) === 'mumbai';
        });
    }

    public function test_orders_cancelled_emits_purchase_cancelled_adjustment(): void
    {
        Event::create([
            'shop_id'     => $this->shop->id,
            'event_name'  => 'Purchase',
            'event_id'    => 'purchase-55',
            'dedup_key'   => 'purchase:55',
            'source'      => 'server',
            'order_id'    => '55',
            'currency'    => 'INR',
            'value'       => 999,
            'occurred_at' => now(),
            'payload'     => ['is_cod' => true, 'user_data' => ['phone' => '9123456789']],
        ]);

        $body = json_encode([
            'id'                    => 55,
            'name'                  => '#1055',
            'currency'              => 'INR',
            'total_price'           => '999.00',
            'cancelled_at'          => '2026-09-17T10:00:00Z',
            'cancel_reason'         => 'customer',
            'payment_gateway_names' => ['Cash on Delivery (COD)'],
            'customer'              => ['phone' => '9123456789'],
        ]);

        $this->postJson('/webhooks', json_decode($body, true), [
            'X-Shopify-Topic'       => 'orders/cancelled',
            'X-Shopify-Shop-Domain' => 'test-store.myshopify.com',
            'X-Shopify-Hmac-Sha256' => $this->hmac($body),
            'X-Shopify-Webhook-Id'  => 'wh-cancel-1',
        ])->assertOk();

        $this->assertDatabaseHas('events', [
            'shop_id'    => $this->shop->id,
            'event_name' => 'PurchaseCancelled',
            'order_id'   => '55',
            'dedup_key'  => 'cancel:55',
        ]);
    }

    public function test_orders_updated_rto_tag_emits_rto_adjustment(): void
    {
        $body = json_encode([
            'id'                    => 66,
            'name'                  => '#1066',
            'currency'              => 'INR',
            'total_price'           => '1499.00',
            'tags'                  => 'rto, delhivery',
            'closed_at'             => '2026-09-17T12:00:00Z',
            'fulfillment_status'    => 'restocked',
            'payment_gateway_names' => ['Cash on Delivery (COD)'],
            'customer'              => ['phone' => '9988776655'],
        ]);

        $this->postJson('/webhooks', json_decode($body, true), [
            'X-Shopify-Topic'       => 'orders/updated',
            'X-Shopify-Shop-Domain' => 'test-store.myshopify.com',
            'X-Shopify-Hmac-Sha256' => $this->hmac($body),
            'X-Shopify-Webhook-Id'  => 'wh-rto-1',
        ])->assertOk();

        $this->assertDatabaseHas('events', [
            'shop_id'    => $this->shop->id,
            'event_name' => 'PurchaseCancelled',
            'order_id'   => '66',
            'dedup_key'  => 'rto:66',
        ]);
    }

    public function test_visitor_bridge_matches_india_phone_variants(): void
    {
        Visitor::create([
            'shop_id'      => $this->shop->id,
            'vid'          => 'v-phone',
            'phone'        => '9876543210',
            'oppref'       => 'opp-phone-1',
            'last_seen_at' => now(),
        ]);

        $body = json_encode([
            'id'              => 77,
            'currency'        => 'INR',
            'total_price'     => '500.00',
            'line_items'      => [],
            'customer'        => ['phone' => '+91 98765 43210'],
            'billing_address' => ['country_code' => 'IN'],
        ]);

        $this->postJson('/webhooks', json_decode($body, true), [
            'X-Shopify-Topic'       => 'orders/create',
            'X-Shopify-Shop-Domain' => 'test-store.myshopify.com',
            'X-Shopify-Hmac-Sha256' => $this->hmac($body),
            'X-Shopify-Webhook-Id'  => 'wh-phone-join-1',
        ])->assertOk();

        $purchase = Event::where('order_id', '77')->where('event_name', 'Purchase')->first();
        $this->assertSame('opp-phone-1', $purchase->payload['user_data']['oppref'] ?? null);
    }

    protected function hmac(string $body): string
    {
        return base64_encode(hash_hmac('sha256', $body, 'test-secret', true));
    }
}
