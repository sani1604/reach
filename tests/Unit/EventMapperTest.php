<?php

namespace Tests\Unit;

use App\Services\EventMapper;
use Tests\TestCase;

class EventMapperTest extends TestCase
{
    protected EventMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new EventMapper;
    }

    public function test_purchase_maps_to_order_created_with_minor_units(): void
    {
        $event = $this->mapper->build('Purchase', [
            'event_id'   => 'purchase-42',
            'event_time' => 1_700_000_000,
            'value'      => 14.99,
            'currency'   => 'USD',
            'source_url' => 'https://shop.example.com/thank-you',
            'oppref'     => 'oppref_abc',
            'products'   => [
                ['id' => 'sku_1', 'title' => 'Ghee', 'price' => 14.99, 'quantity' => 1],
            ],
            'user_data'  => [
                'email' => 'Buyer@Example.com',
                'phone' => '+1 (415) 555-2671',
                'obref' => 'obref-1',
            ],
        ]);

        $this->assertSame('purchase-42', $event['id']);
        $this->assertSame('order_created', $event['type']);
        $this->assertSame(1_700_000_000_000, $event['timestamp_ms']);
        $this->assertSame('web', $event['action_source']);
        $this->assertSame('https://shop.example.com/thank-you', $event['source_url']);
        $this->assertSame('oppref_abc', $event['oppref']);

        $this->assertSame('contents', $event['data']['type']);
        $this->assertSame(1499, $event['data']['amount']);
        $this->assertSame('USD', $event['data']['currency']);
        $this->assertSame('sku_1', $event['data']['contents'][0]['id']);
        $this->assertSame('Ghee', $event['data']['contents'][0]['name']);
        $this->assertSame(1499, $event['data']['contents'][0]['amount']);

        $this->assertSame(
            hash('sha256', 'buyer@example.com'),
            $event['user']['emails_sha256'][0]
        );
        $this->assertSame(
            hash('sha256', '14155552671'),
            $event['user']['phone_numbers_sha256'][0]
        );
        $this->assertSame('obref-1', $event['user']['obref']);
    }

    public function test_add_to_cart_maps_to_items_added(): void
    {
        $event = $this->mapper->build('AddToCart', [
            'event_id'   => 'px-1',
            'value'      => 899,
            'currency'   => 'INR',
            'source_url' => 'https://shop.example.com/products/ghee',
            'content_ids'=> ['1002'],
        ]);

        $this->assertSame('items_added', $event['type']);
        $this->assertSame(89900, $event['data']['amount']); // INR has decimals
        $this->assertSame('INR', $event['data']['currency']);
        $this->assertSame('1002', $event['data']['contents'][0]['id']);
    }

    public function test_page_view_maps_to_page_viewed(): void
    {
        $event = $this->mapper->build('PageView', [
            'event_id' => 'px-2',
            'url'      => 'https://shop.example.com/',
        ]);

        $this->assertSame('page_viewed', $event['type']);
        $this->assertSame('contents', $event['data']['type']);
        $this->assertSame('https://shop.example.com/', $event['source_url']);
    }

    public function test_zero_decimal_currency_jpy(): void
    {
        $this->assertSame(1500, $this->mapper->toMinorUnits(1500, 'JPY'));
        $this->assertSame(150000, $this->mapper->toMinorUnits(1500, 'USD'));
    }

    public function test_test_event_is_custom(): void
    {
        $event = $this->mapper->build('TestEvent', [
            'event_id' => 'reach-test-1',
            'custom_event_name' => 'reach_test_connection',
            'source_url' => 'https://shop.example.com',
        ]);

        $this->assertSame('custom', $event['type']);
        $this->assertSame('reach_test_connection', $event['custom_event_name']);
    }

    public function test_india_phone_gets_91_prefix_before_hash(): void
    {
        $this->assertSame('919876543210', $this->mapper->normalizePhoneDigits('9876543210', 'IN'));
        $this->assertSame('919876543210', $this->mapper->normalizePhoneDigits('+91 98765 43210', 'IN'));
        $this->assertSame('919876543210', $this->mapper->normalizePhoneDigits('09876543210', 'IN'));
        $this->assertSame('919876543210', $this->mapper->normalizePhoneDigits('91-9876543210'));

        $event = $this->mapper->build('Purchase', [
            'event_id'   => 'p-in-1',
            'value'      => 1499,
            'currency'   => 'INR',
            'source_url' => 'https://shop.example.com/thank-you',
            'user_data'  => [
                'phone'   => '9876543210',
                'country' => 'IN',
                'city'    => 'Delhi',
                'state'   => 'DL',
                'postal_code' => '110001',
            ],
        ]);

        $this->assertSame(
            hash('sha256', '919876543210'),
            $event['user']['phone_numbers_sha256'][0]
        );
        // Phone-first: phones appear even when email is absent.
        $this->assertArrayNotHasKey('emails_sha256', $event['user']);
        $this->assertSame(['delhi'], $event['user']['cities']);
        $this->assertSame(['dl'], $event['user']['regions']);
        $this->assertSame(['110001'], $event['user']['postal_codes']);
        $this->assertSame(['IN'], $event['user']['countries']);
    }

    public function test_oai_click_id_aliases_map_to_oppref(): void
    {
        $event = $this->mapper->build('PageView', [
            'event_id' => 'px-oai',
            'url'      => 'https://shop.example.com/?oai_click_id=clk_99',
            'user_data'=> ['oai_click_id' => 'clk_99'],
        ]);

        $this->assertSame('clk_99', $event['oppref']);
    }

    public function test_add_payment_info_is_custom_event(): void
    {
        $event = $this->mapper->build('AddPaymentInfo', [
            'event_id'   => 'pay-1',
            'value'      => 999,
            'currency'   => 'INR',
            'source_url' => 'https://shop.example.com/checkouts',
        ]);

        $this->assertSame('custom', $event['type']);
        $this->assertSame('add_payment_info', $event['custom_event_name']);
        $this->assertSame(99900, $event['data']['amount']);
    }
}
