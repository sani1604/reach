<?php

namespace App\Services;

class ShopifyWebhook
{
    /**
     * Verify a webhook request. Shopify signs the raw JSON body with HMAC-SHA256
     * and ships it in the X-Shopify-Hmac-Sha256 header.
     */
    public static function verify(string $rawBody, ?string $hmacHeader): bool
    {
        if (! $hmacHeader) {
            return false;
        }

        $calculated = base64_encode(hash_hmac('sha256', $rawBody, (string) config('shopify.api_secret'), true));

        return hash_equals($hmacHeader, $calculated);
    }

    /**
     * Verify the OAuth callback query string (hmac + signature params removed,
     * sorted alphabetically, and hashed with HMAC-SHA256 hex digest per Shopify spec).
     */
    public static function verifyOAuthQueryString(string $rawQuery): bool
    {
        parse_str($rawQuery, $params);
        $hmac = $params['hmac'] ?? null;
        if (! $hmac) {
            return false;
        }

        unset($params['hmac'], $params['signature']);
        ksort($params);

        $calculated = hash_hmac('sha256', http_build_query($params), (string) config('shopify.api_secret'));

        return hash_equals($hmac, $calculated);
    }
}
