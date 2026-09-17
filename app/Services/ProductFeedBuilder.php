<?php

namespace App\Services;

use App\Models\Shop;
use Illuminate\Support\Str;

/**
 * Builds an OpenAI Ads / ChatGPT product feed from the Shopify catalog.
 *
 * Spec-aligned fields (Google Shopping-compatible + OpenAI extras):
 *   item_id, title, description, url, brand, price, sale_price,
 *   availability, image_url, additional_image_urls, gtin, mpn,
 *   group_id, condition, product_category, seller_name, seller_url,
 *   store_country, target_countries, is_ads_eligible
 *
 * @see https://developers.openai.com/commerce/product-feeds/spec
 */
class ProductFeedBuilder
{
    public function __construct(private ShopifyClient $shopify)
    {
    }

    /**
     * Fetch variants from Shopify and map them to feed rows + validation issues.
     *
     * @return array{
     *   items: list<array<string, mixed>>,
     *   issues: list<array{level:string,code:string,message:string,item_id?:string}>,
     *   stats: array{total:int,ready:int,issues:int,out_of_stock:int,missing_image:int,missing_price:int}
     * }
     */
    public function build(Shop $shop, int $limit = 2500): array
    {
        $currency = 'USD';
        $storeCountry = 'US';
        $shopName = $shop->shopify_domain;
        $shopUrl = 'https://'.$shop->shopify_domain;

        try {
            $shopInfo = $this->shopify->getShop($shop);
            if (! empty($shopInfo['currency'])) {
                $currency = strtoupper((string) $shopInfo['currency']);
            }
            if (! empty($shopInfo['country_code'])) {
                $storeCountry = strtoupper((string) $shopInfo['country_code']);
            } elseif (! empty($shopInfo['country'])) {
                $storeCountry = strtoupper(substr((string) $shopInfo['country'], 0, 2));
            }
            if (! empty($shopInfo['name'])) {
                $shopName = (string) $shopInfo['name'];
            }
            if (! empty($shopInfo['domain'])) {
                $shopUrl = 'https://'.$shopInfo['domain'];
            } elseif (! empty($shopInfo['myshopify_domain'])) {
                $shopUrl = 'https://'.$shopInfo['myshopify_domain'];
            }
        } catch (\Throwable $e) {
            logger()->warning('Product feed: shop.json failed', [
                'shop'  => $shop->shopify_domain,
                'error' => $e->getMessage(),
            ]);
        }

        $variants = $this->fetchVariants($shop, $limit);
        $items = [];
        $issues = [];

        $stats = [
            'total'         => 0,
            'ready'         => 0,
            'issues'        => 0,
            'out_of_stock'  => 0,
            'missing_image' => 0,
            'missing_price' => 0,
        ];

        foreach ($variants as $row) {
            $mapped = $this->mapVariant($row, [
                'currency'      => $currency,
                'store_country' => $storeCountry,
                'seller_name'   => $shopName,
                'seller_url'    => $shopUrl,
                'shop_url'      => $shopUrl,
            ]);

            $itemIssues = $this->validate($mapped);
            $stats['total']++;

            if ($mapped['availability'] === 'out_of_stock') {
                $stats['out_of_stock']++;
            }

            $blocking = false;
            foreach ($itemIssues as $issue) {
                $issues[] = $issue;
                if (($issue['code'] ?? '') === 'missing_image') {
                    $stats['missing_image']++;
                }
                if (($issue['code'] ?? '') === 'missing_price') {
                    $stats['missing_price']++;
                }
                // info-level notes (e.g. out_of_stock) do not block ads eligibility.
                if (($issue['level'] ?? '') === 'error') {
                    $blocking = true;
                }
            }

            if ($blocking) {
                $stats['issues']++;
            } else {
                $stats['ready']++;
            }

            $items[] = $mapped;
        }

        return compact('items', 'issues', 'stats');
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function fetchVariants(Shop $shop, int $limit): array
    {
        $out = [];
        $cursor = null;
        $pageSize = min(50, max(10, $limit));

        do {
            $after = $cursor ? ', after: "'.addslashes($cursor).'"' : '';
            $query = <<<GRAPHQL
            query {
              products(first: {$pageSize}{$after}) {
                pageInfo { hasNextPage endCursor }
                edges {
                  node {
                    id
                    title
                    handle
                    description
                    productType
                    vendor
                    status
                    onlineStoreUrl
                    featuredImage { url }
                    images(first: 5) { edges { node { url } } }
                    variants(first: 50) {
                      edges {
                        node {
                          id
                          title
                          sku
                          barcode
                          price
                          compareAtPrice
                          availableForSale
                          inventoryQuantity
                          image { url }
                          selectedOptions { name value }
                        }
                      }
                    }
                  }
                }
              }
            }
            GRAPHQL;

            $result = $this->shopify->graphql($shop, $query);
            $connection = $result['data']['products'] ?? null;

            if (! $connection) {
                // Fallback to REST if GraphQL products fails (scope / API issues).
                return $this->fetchVariantsRest($shop, $limit);
            }

            foreach ($connection['edges'] ?? [] as $edge) {
                $product = $edge['node'] ?? [];
                if (($product['status'] ?? '') === 'DRAFT') {
                    continue;
                }

                foreach ($product['variants']['edges'] ?? [] as $vEdge) {
                    $variant = $vEdge['node'] ?? [];
                    $out[] = [
                        'product' => $product,
                        'variant' => $variant,
                    ];
                    if (count($out) >= $limit) {
                        return $out;
                    }
                }
            }

            $hasNext = (bool) ($connection['pageInfo']['hasNextPage'] ?? false);
            $cursor = $connection['pageInfo']['endCursor'] ?? null;
        } while ($hasNext && $cursor && count($out) < $limit);

        return $out;
    }

    /**
     * REST fallback for older tokens / API quirks.
     *
     * @return list<array<string, mixed>>
     */
    protected function fetchVariantsRest(Shop $shop, int $limit): array
    {
        $out = [];
        $pageInfo = null;
        $fetched = 0;

        do {
            $query = ['limit' => 50, 'status' => 'active'];
            // Simple first-page fetch — good enough for free-tier catalogs.
            $res = $this->shopify->get($shop, '/products.json', $query);
            $products = $res->json('products', []) ?: [];

            foreach ($products as $product) {
                foreach ($product['variants'] ?? [] as $variant) {
                    $out[] = [
                        'product' => [
                            'id'             => 'gid://shopify/Product/'.($product['id'] ?? ''),
                            'title'          => $product['title'] ?? '',
                            'handle'         => $product['handle'] ?? '',
                            'description'    => strip_tags((string) ($product['body_html'] ?? '')),
                            'productType'    => $product['product_type'] ?? '',
                            'vendor'         => $product['vendor'] ?? '',
                            'onlineStoreUrl' => null,
                            'featuredImage'  => ['url' => $product['image']['src'] ?? null],
                            'images'         => [
                                'edges' => array_map(
                                    fn ($img) => ['node' => ['url' => $img['src'] ?? '']],
                                    $product['images'] ?? []
                                ),
                            ],
                        ],
                        'variant' => [
                            'id'                => 'gid://shopify/ProductVariant/'.($variant['id'] ?? ''),
                            'title'             => $variant['title'] ?? '',
                            'sku'               => $variant['sku'] ?? '',
                            'barcode'           => $variant['barcode'] ?? '',
                            'price'             => $variant['price'] ?? null,
                            'compareAtPrice'    => $variant['compare_at_price'] ?? null,
                            'availableForSale'  => ($variant['inventory_quantity'] ?? 0) > 0
                                || ($variant['inventory_management'] ?? null) === null,
                            'inventoryQuantity' => $variant['inventory_quantity'] ?? null,
                            'image'             => null,
                            'selectedOptions'   => [],
                        ],
                    ];
                    $fetched++;
                    if ($fetched >= $limit) {
                        return $out;
                    }
                }
            }

            break; // single page REST for shared-hosting friendliness
        } while (false);

        return $out;
    }

    /**
     * @param  array{product: array, variant: array}  $row
     * @param  array{currency:string,store_country:string,seller_name:string,seller_url:string,shop_url:string}  $ctx
     * @return array<string, mixed>
     */
    protected function mapVariant(array $row, array $ctx): array
    {
        $product = $row['product'];
        $variant = $row['variant'];

        $variantId = $this->numericId($variant['id'] ?? '');
        $productId = $this->numericId($product['id'] ?? '');
        $handle = (string) ($product['handle'] ?? '');

        $title = trim((string) ($product['title'] ?? 'Product'));
        $vTitle = trim((string) ($variant['title'] ?? ''));
        if ($vTitle && strtolower($vTitle) !== 'default title') {
            $title = $title.' — '.$vTitle;
        }
        $title = Str::limit($title, 150, '');

        $description = trim(strip_tags((string) ($product['description'] ?? '')));
        if ($description === '') {
            $description = $title;
        }
        $description = Str::limit($description, 5000, '');

        $url = $product['onlineStoreUrl']
            ?? ($handle ? rtrim($ctx['shop_url'], '/').'/products/'.$handle : $ctx['shop_url']);
        if ($variantId && $handle) {
            $url = rtrim($ctx['shop_url'], '/').'/products/'.$handle.'?variant='.$variantId;
        }

        $image = $variant['image']['url']
            ?? ($product['featuredImage']['url'] ?? null);
        $extraImages = [];
        foreach ($product['images']['edges'] ?? [] as $edge) {
            $u = $edge['node']['url'] ?? null;
            if ($u && $u !== $image) {
                $extraImages[] = $u;
            }
        }

        $price = $variant['price'] ?? null;
        $compare = $variant['compareAtPrice'] ?? null;
        $currency = $ctx['currency'];

        $available = (bool) ($variant['availableForSale'] ?? false);
        $qty = $variant['inventoryQuantity'] ?? null;
        if ($qty !== null && (int) $qty <= 0) {
            $available = false;
        }

        $brand = trim((string) ($product['vendor'] ?? '')) ?: $ctx['seller_name'];
        $brand = Str::limit($brand, 70, '');

        $gtin = preg_replace('/\D+/', '', (string) ($variant['barcode'] ?? '')) ?: null;
        if ($gtin && (strlen($gtin) < 8 || strlen($gtin) > 14)) {
            $gtin = null;
        }

        $item = [
            'item_id'               => $variantId ?: ($variant['sku'] ?: Str::uuid()->toString()),
            'group_id'              => $productId ?: null,
            'title'                 => $title,
            'description'           => $description,
            'url'                   => $url,
            'brand'                 => $brand,
            'price'                 => $price !== null && $price !== '' ? number_format((float) $price, 2, '.', '').' '.$currency : null,
            'sale_price'            => null,
            'availability'          => $available ? 'in_stock' : 'out_of_stock',
            'image_url'             => $image,
            'additional_image_urls' => $extraImages ? implode(',', array_slice($extraImages, 0, 10)) : null,
            'gtin'                  => $gtin,
            'mpn'                   => $variant['sku'] ?: null,
            'condition'             => 'new',
            'product_category'      => $product['productType'] ?: null,
            'seller_name'           => Str::limit($ctx['seller_name'], 70, ''),
            'seller_url'            => $ctx['seller_url'],
            'store_country'         => $ctx['store_country'],
            'target_countries'      => $ctx['store_country'],
            'is_ads_eligible'       => $available && $image && $price !== null && $price !== '' ? 'true' : 'false',
        ];

        // If compare-at is higher, treat current price as sale_price.
        if ($compare !== null && $compare !== '' && $price !== null && (float) $compare > (float) $price) {
            $item['price'] = number_format((float) $compare, 2, '.', '').' '.$currency;
            $item['sale_price'] = number_format((float) $price, 2, '.', '').' '.$currency;
        }

        return $item;
    }

    /**
     * @return list<array{level:string,code:string,message:string,item_id?:string}>
     */
    protected function validate(array $item): array
    {
        $issues = [];
        $id = (string) ($item['item_id'] ?? '');

        if (empty($item['title'])) {
            $issues[] = $this->issue('error', 'missing_title', 'Title is required.', $id);
        }
        if (empty($item['description'])) {
            $issues[] = $this->issue('warn', 'missing_description', 'Description is empty — using title fallback.', $id);
        }
        if (empty($item['url'])) {
            $issues[] = $this->issue('error', 'missing_url', 'Product URL is required.', $id);
        }
        if (empty($item['image_url'])) {
            $issues[] = $this->issue('error', 'missing_image', 'Main image is missing — OpenAI will reject this item.', $id);
        }
        if (empty($item['price'])) {
            $issues[] = $this->issue('error', 'missing_price', 'Price is missing.', $id);
        }
        if (empty($item['brand'])) {
            $issues[] = $this->issue('warn', 'missing_brand', 'Brand is empty.', $id);
        }
        if (($item['availability'] ?? '') === 'out_of_stock') {
            $issues[] = $this->issue('info', 'out_of_stock', 'Variant is out of stock (still included, marked out_of_stock).', $id);
        }

        return $issues;
    }

    protected function issue(string $level, string $code, string $message, string $itemId): array
    {
        return [
            'level'   => $level,
            'code'    => $code,
            'message' => $message,
            'item_id' => $itemId ?: null,
        ];
    }

    protected function numericId(string $gid): string
    {
        if ($gid === '') {
            return '';
        }
        if (preg_match('/\/(\d+)\s*$/', $gid, $m)) {
            return $m[1];
        }

        return preg_replace('/\D+/', '', $gid) ?: $gid;
    }

    /**
     * Render feed items as TSV (OpenAI / Google Shopping friendly).
     *
     * @param  list<array<string, mixed>>  $items
     */
    public function toTsv(array $items): string
    {
        $columns = [
            'item_id', 'group_id', 'title', 'description', 'url', 'brand',
            'price', 'sale_price', 'availability', 'image_url', 'additional_image_urls',
            'gtin', 'mpn', 'condition', 'product_category',
            'seller_name', 'seller_url', 'store_country', 'target_countries',
            'is_ads_eligible',
        ];

        $lines = [implode("\t", $columns)];
        foreach ($items as $item) {
            $row = [];
            foreach ($columns as $col) {
                $val = $item[$col] ?? '';
                $val = str_replace(["\t", "\r", "\n"], ' ', (string) $val);
                $row[] = $val;
            }
            $lines[] = implode("\t", $row);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    public function toCsv(array $items): string
    {
        $columns = [
            'item_id', 'group_id', 'title', 'description', 'url', 'brand',
            'price', 'sale_price', 'availability', 'image_url', 'additional_image_urls',
            'gtin', 'mpn', 'condition', 'product_category',
            'seller_name', 'seller_url', 'store_country', 'target_countries',
            'is_ads_eligible',
        ];

        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, $columns);
        foreach ($items as $item) {
            $row = [];
            foreach ($columns as $col) {
                $row[] = $item[$col] ?? '';
            }
            fputcsv($fh, $row);
        }
        rewind($fh);
        $csv = stream_get_contents($fh) ?: '';
        fclose($fh);

        return $csv;
    }
}
