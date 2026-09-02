<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReachTrackingTest extends TestCase
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
            'installed_at'   => now(),
        ]);
    }

    public function test_pixel_config_is_enabled_for_installed_shop(): void
    {
        $this->getJson('/api/pixel-config?shop=test-store.myshopify.com')
            ->assertOk()
            ->assertJson([
                'enabled'  => true,
                'pixel_id' => 'PX-123',
            ]);
    }

    public function test_pixel_config_disabled_without_pixel_id(): void
    {
        $this->shop->update(['pixel_id' => null]);

        $this->getJson('/api/pixel-config?shop=test-store.myshopify.com')
            ->assertOk()
            ->assertJson(['enabled' => false]);
    }

    public function test_track_records_and_maps_browser_event(): void
    {
        $this->postJson('/api/track', [
            'shop'       => 'test-store.myshopify.com',
            'event_name' => 'product_added_to_cart',
            'data'       => ['value' => 899, 'currency' => 'INR'],
        ])->assertStatus(202);

        $this->assertDatabaseHas('events', [
            'shop_id'    => $this->shop->id,
            'event_name' => 'AddToCart',
            'source'     => 'browser',
        ]);
    }

    public function test_track_skips_browser_purchase(): void
    {
        $this->postJson('/api/track', [
            'shop'       => 'test-store.myshopify.com',
            'event_name' => 'Purchase',
            'data'       => [],
        ])->assertJson(['skipped' => true]);

        $this->assertDatabaseMissing('events', ['event_name' => 'Purchase']);
    }

    public function test_webhook_with_valid_hmac_records_purchase(): void
    {
        $body = json_encode([
            'id'          => 42,
            'name'        => '#1001',
            'currency'    => 'INR',
            'total_price' => '1499.00',
            'line_items'  => [[
                'product_id' => '1002',
                'title'      => 'A2 Gir Cow Ghee 1L',
                'price'      => '899.00',
                'quantity'   => 1,
            ]],
            'customer' => ['email' => 'buyer@example.com'],
        ]);

        $this->postJson('/webhooks', json_decode($body, true), [
            'X-Shopify-Topic'       => 'orders/create',
            'X-Shopify-Shop-Domain' => 'test-store.myshopify.com',
            'X-Shopify-Hmac-Sha256' => $this->hmac($body),
        ])->assertOk();

        $this->assertDatabaseHas('events', [
            'shop_id'    => $this->shop->id,
            'event_name' => 'Purchase',
            'order_id'   => '42',
            'source'     => 'server',
            'value'      => 1499.00,
        ]);
    }

    public function test_duplicate_order_is_deduplicated(): void
    {
        $body = json_encode([
            'id'          => 42,
            'currency'    => 'INR',
            'total_price' => '1499.00',
            'line_items'  => [],
        ]);
        $headers = [
            'X-Shopify-Topic'       => 'orders/create',
            'X-Shopify-Shop-Domain' => 'test-store.myshopify.com',
            'X-Shopify-Hmac-Sha256' => $this->hmac($body),
        ];

        $this->postJson('/webhooks', json_decode($body, true), $headers)->assertOk();
        $this->postJson('/webhooks', json_decode($body, true), $headers)->assertOk();

        $this->assertSame(
            1,
            Event::where('order_id', '42')->where('event_name', 'Purchase')->count()
        );
    }

    public function test_webhook_with_invalid_hmac_is_rejected(): void
    {
        $this->postJson('/webhooks', ['id' => 42], [
            'X-Shopify-Topic'       => 'orders/create',
            'X-Shopify-Shop-Domain' => 'test-store.myshopify.com',
            'X-Shopify-Hmac-Sha256' => 'bogus',
        ])->assertUnauthorized();
    }

    public function test_refund_webhook_records_purchase_cancelled(): void
    {
        $body = json_encode([
            'id'           => 777,
            'order_id'     => 42,
            'transactions' => [
                ['kind' => 'refund', 'amount' => '499.00', 'currency' => 'INR'],
            ],
        ]);

        $this->postJson('/webhooks', json_decode($body, true), [
            'X-Shopify-Topic'       => 'refunds/create',
            'X-Shopify-Shop-Domain' => 'test-store.myshopify.com',
            'X-Shopify-Hmac-Sha256' => $this->hmac($body),
        ])->assertOk();

        $this->assertDatabaseHas('events', [
            'shop_id'    => $this->shop->id,
            'event_name' => 'PurchaseCancelled',
            'order_id'   => '42',
            'source'     => 'server',
            'value'      => 499.00,
        ]);
    }

    public function test_webhook_delivery_is_idempotent_by_webhook_id(): void
    {
        $body1 = json_encode([
            'id'          => 42,
            'currency'    => 'INR',
            'total_price' => '1499.00',
            'line_items'  => [],
        ]);
        $body2 = json_encode([
            'id'          => 99,
            'currency'    => 'INR',
            'total_price' => '199.00',
            'line_items'  => [],
        ]);

        $this->postJson('/webhooks', json_decode($body1, true), [
            'X-Shopify-Topic'       => 'orders/create',
            'X-Shopify-Shop-Domain' => 'test-store.myshopify.com',
            'X-Shopify-Hmac-Sha256' => $this->hmac($body1),
            'X-Shopify-Webhook-Id'  => 'wh-1',
        ])->assertOk();

        // Same delivery ID, different order — must be skipped entirely.
        $this->postJson('/webhooks', json_decode($body2, true), [
            'X-Shopify-Topic'       => 'orders/create',
            'X-Shopify-Shop-Domain' => 'test-store.myshopify.com',
            'X-Shopify-Hmac-Sha256' => $this->hmac($body2),
            'X-Shopify-Webhook-Id'  => 'wh-1',
        ])->assertJson(['duplicate' => true]);

        $this->assertDatabaseHas('events', ['order_id' => '42']);
        $this->assertDatabaseMissing('events', ['order_id' => '99']);
    }

    public function test_privacy_webhooks_are_accepted_and_redact_data(): void
    {
        // Seed a visitor holding PII for this customer.
        $this->shop->visitors()->create([
            'vid'   => 'visitor-1',
            'email' => 'buyer@example.com',
            'phone' => '+911234567890',
        ]);

        $payload = json_encode([
            'shop_id'  => 1,
            'customer' => [
                'id'    => 99,
                'email' => 'buyer@example.com',
            ],
        ]);

        $headers = [
            'X-Shopify-Shop-Domain' => 'test-store.myshopify.com',
            'X-Shopify-Hmac-Sha256' => $this->hmac($payload),
        ];

        // customers/data_request — must acknowledge 200.
        $this->postJson('/webhooks', json_decode($payload, true), array_merge($headers, [
            'X-Shopify-Topic' => 'customers/data_request',
        ]))->assertOk();

        // customers/redact — deletes the matching visitor rows.
        $this->postJson('/webhooks', json_decode($payload, true), array_merge($headers, [
            'X-Shopify-Topic' => 'customers/redact',
        ]))->assertOk();

        $this->assertDatabaseMissing('visitors', [
            'shop_id' => $this->shop->id,
            'vid'     => 'visitor-1',
        ]);

        // shop/redact — deletes the whole store record.
        $payload = json_encode(['shop_id' => 1]);
        $this->postJson('/webhooks', json_decode($payload, true), [
            'X-Shopify-Topic'       => 'shop/redact',
            'X-Shopify-Shop-Domain' => 'test-store.myshopify.com',
            'X-Shopify-Hmac-Sha256' => $this->hmac($payload),
        ])->assertOk();

        $this->assertDatabaseMissing('shops', [
            'shopify_domain' => 'test-store.myshopify.com',
        ]);
    }

    protected function hmac(string $body): string
    {
        return base64_encode(hash_hmac('sha256', $body, 'test-secret', true));
    }
}
