<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Shop;
use App\Services\ProductFeedBuilder;
use App\Services\ShopifyClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class ReachFeedAndPerformanceTest extends TestCase
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
            'web_pixel_id'   => 'gid://shopify/WebPixel/1',
            'installed_at'   => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    protected function actingAsShop()
    {
        return $this->withSession(['shop' => $this->shop->shopify_domain]);
    }

    public function test_product_feed_builder_maps_variants_and_exports_tsv(): void
    {
        $client = Mockery::mock(ShopifyClient::class);
        $client->shouldReceive('getShop')->andReturn([
            'name'         => 'Test Store',
            'currency'     => 'INR',
            'country_code' => 'IN',
            'domain'       => 'test-store.myshopify.com',
        ]);
        $client->shouldReceive('graphql')->andReturn([
            'data' => [
                'products' => [
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                    'edges'    => [
                        [
                            'node' => [
                                'id'             => 'gid://shopify/Product/11',
                                'title'          => 'Silk Saree',
                                'handle'         => 'silk-saree',
                                'description'    => 'Handloom silk saree',
                                'productType'    => 'Apparel',
                                'vendor'         => 'Reach Brand',
                                'status'         => 'ACTIVE',
                                'onlineStoreUrl' => 'https://test-store.myshopify.com/products/silk-saree',
                                'featuredImage'  => ['url' => 'https://cdn.example/saree.jpg'],
                                'images'         => ['edges' => []],
                                'variants'       => [
                                    'edges' => [
                                        [
                                            'node' => [
                                                'id'                => 'gid://shopify/ProductVariant/99',
                                                'title'             => 'Default Title',
                                                'sku'               => 'SAREE-1',
                                                'barcode'           => '8901234567890',
                                                'price'             => '2499.00',
                                                'compareAtPrice'    => '2999.00',
                                                'availableForSale'  => true,
                                                'inventoryQuantity' => 5,
                                                'image'             => null,
                                                'selectedOptions'   => [],
                                            ],
                                        ],
                                        [
                                            'node' => [
                                                'id'                => 'gid://shopify/ProductVariant/100',
                                                'title'             => 'Out',
                                                'sku'               => 'SAREE-2',
                                                'barcode'           => '',
                                                'price'             => '1999.00',
                                                'compareAtPrice'    => null,
                                                'availableForSale'  => false,
                                                'inventoryQuantity' => 0,
                                                'image'             => null,
                                                'selectedOptions'   => [],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $builder = new ProductFeedBuilder($client);
        $built = $builder->build($this->shop);

        $this->assertSame(2, $built['stats']['total']);
        $this->assertSame(2, $built['stats']['ready']); // OOS is info, still ready
        $this->assertSame(1, $built['stats']['out_of_stock']);
        $this->assertSame('99', $built['items'][0]['item_id']);
        $this->assertStringContainsString('INR', $built['items'][0]['price']);
        $this->assertSame('2499.00 INR', $built['items'][0]['sale_price']);
        $this->assertSame('IN', $built['items'][0]['store_country']);
        $this->assertSame('true', $built['items'][0]['is_ads_eligible']);

        $tsv = $builder->toTsv($built['items']);
        $this->assertStringContainsString("item_id\tgroup_id\ttitle", $tsv);
        $this->assertStringContainsString("99\t11\tSilk Saree", $tsv);

        $csv = $builder->toCsv($built['items']);
        $this->assertStringContainsString('item_id,group_id,title', $csv);
        $this->assertStringContainsString('99,11,', $csv);
    }

    public function test_product_feed_builder_flags_missing_image(): void
    {
        $client = Mockery::mock(ShopifyClient::class);
        $client->shouldReceive('getShop')->andReturn([
            'currency' => 'USD', 'country_code' => 'US', 'name' => 'Shop',
        ]);
        $client->shouldReceive('graphql')->andReturn([
            'data' => [
                'products' => [
                    'pageInfo' => ['hasNextPage' => false],
                    'edges'    => [[
                        'node' => [
                            'id' => 'gid://shopify/Product/1', 'title' => 'Bare', 'handle' => 'bare',
                            'description' => '', 'productType' => '', 'vendor' => '', 'status' => 'ACTIVE',
                            'onlineStoreUrl' => null, 'featuredImage' => null, 'images' => ['edges' => []],
                            'variants' => ['edges' => [[
                                'node' => [
                                    'id' => 'gid://shopify/ProductVariant/2', 'title' => 'Default Title',
                                    'sku' => '', 'barcode' => '', 'price' => '10.00', 'compareAtPrice' => null,
                                    'availableForSale' => true, 'inventoryQuantity' => 1, 'image' => null,
                                    'selectedOptions' => [],
                                ],
                            ]]],
                        ],
                    ]],
                ],
            ],
        ]);

        $built = (new ProductFeedBuilder($client))->build($this->shop);
        $this->assertSame(1, $built['stats']['total']);
        $this->assertSame(0, $built['stats']['ready']);
        $this->assertSame(1, $built['stats']['issues']);
        $this->assertSame(1, $built['stats']['missing_image']);
        $this->assertSame('false', $built['items'][0]['is_ads_eligible']);
    }

    public function test_public_feed_download_requires_valid_token(): void
    {
        $this->shop->update([
            'feed_token'      => 'feed-secret-token-abc',
            'feed_item_count' => 1,
            'feed_status'     => 'ready',
            'feed_synced_at'  => now(),
        ]);

        $this->get('/feed/test-store.myshopify.com/wrong-token')
            ->assertNotFound();
    }

    public function test_public_feed_download_returns_tsv(): void
    {
        $this->shop->update([
            'feed_token' => 'feed-secret-token-abc',
        ]);

        Http::fake([
            '*/shop.json' => Http::response([
                'shop' => [
                    'name'         => 'Test Store',
                    'currency'     => 'INR',
                    'country_code' => 'IN',
                    'domain'       => 'test-store.myshopify.com',
                ],
            ], 200),
            '*/graphql.json' => Http::response([
                'data' => [
                    'products' => [
                        'pageInfo' => ['hasNextPage' => false],
                        'edges'    => [[
                            'node' => [
                                'id' => 'gid://shopify/Product/1', 'title' => 'Kurta', 'handle' => 'kurta',
                                'description' => 'Cotton kurta', 'productType' => 'Apparel',
                                'vendor' => 'Brand', 'status' => 'ACTIVE',
                                'onlineStoreUrl' => 'https://test-store.myshopify.com/products/kurta',
                                'featuredImage' => ['url' => 'https://cdn.example/k.jpg'],
                                'images' => ['edges' => []],
                                'variants' => ['edges' => [[
                                    'node' => [
                                        'id' => 'gid://shopify/ProductVariant/7', 'title' => 'M',
                                        'sku' => 'K-M', 'barcode' => '', 'price' => '899.00',
                                        'compareAtPrice' => null, 'availableForSale' => true,
                                        'inventoryQuantity' => 3, 'image' => null, 'selectedOptions' => [],
                                    ],
                                ]]],
                            ],
                        ]],
                    ],
                ],
            ], 200),
            '*' => Http::response(['ok' => true], 200),
        ]);

        $response = $this->get('/feed/test-store.myshopify.com/feed-secret-token-abc?format=tsv');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/tab-separated-values; charset=UTF-8');
        $this->assertStringContainsString('item_id', $response->getContent());
        $this->assertStringContainsString("7\t1\tKurta — M", $response->getContent());

        $this->shop->refresh();
        $this->assertSame(1, (int) $this->shop->feed_item_count);
        $this->assertNotNull($this->shop->feed_synced_at);
        $this->assertSame('ready', $this->shop->feed_status);
    }

    public function test_feed_page_renders_for_authenticated_shop(): void
    {
        Http::fake([
            '*/shop.json' => Http::response([
                'shop' => ['name' => 'Test', 'currency' => 'INR', 'country_code' => 'IN'],
            ], 200),
            '*/graphql.json' => Http::response([
                'data' => [
                    'products' => [
                        'pageInfo' => ['hasNextPage' => false],
                        'edges'    => [],
                    ],
                ],
            ], 200),
            '*' => Http::response(['ok' => true], 200),
        ]);

        $this->actingAsShop()
            ->get('/feed')
            ->assertOk()
            ->assertSee('Product Feed')
            ->assertSee('Keep your catalog ready for OpenAI Ads');

        $this->shop->refresh();
        $this->assertNotEmpty($this->shop->feed_token);
    }

    public function test_performance_page_shows_revenue_and_funnel(): void
    {
        Event::create([
            'shop_id'     => $this->shop->id,
            'event_name'  => 'PageView',
            'event_id'    => 'pv-1',
            'source'      => 'browser',
            'payload'     => ['utm_source' => 'chatgpt', 'utm_campaign' => 'spring'],
            'occurred_at' => now()->subDay(),
        ]);
        Event::create([
            'shop_id'     => $this->shop->id,
            'event_name'  => 'ViewContent',
            'event_id'    => 'vc-1',
            'source'      => 'browser',
            'payload'     => [],
            'occurred_at' => now()->subDay(),
        ]);
        Event::create([
            'shop_id'     => $this->shop->id,
            'event_name'  => 'AddToCart',
            'event_id'    => 'atc-1',
            'source'      => 'browser',
            'payload'     => [],
            'occurred_at' => now()->subHours(12),
        ]);
        Event::create([
            'shop_id'     => $this->shop->id,
            'event_name'  => 'Purchase',
            'event_id'    => 'pur-1',
            'source'      => 'server',
            'order_id'    => '1001',
            'order_name'  => '#1001',
            'value'       => 2499,
            'currency'    => 'INR',
            'payload'     => [
                'oppref'    => 'opp-abc',
                'user_data' => ['email' => 'hash'],
                'products'  => [['title' => 'Silk Saree', 'quantity' => 1, 'price' => 2499]],
            ],
            'occurred_at' => now()->subHours(6),
        ]);

        $this->actingAsShop()
            ->get('/performance?days=30')
            ->assertOk()
            ->assertSee('Performance')
            ->assertSee('Net revenue')
            ->assertSee('2,499')
            ->assertSee('Silk Saree')
            ->assertSee('#1001')
            ->assertSee('spring');
    }

    public function test_nav_includes_performance_and_feed_links(): void
    {
        $this->actingAsShop()
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Performance', false)
            ->assertSee('Product Feed', false);
    }
}
