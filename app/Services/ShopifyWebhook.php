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
     * computed over the raw query string exactly as Shopify sent it).
     */
    public static function verifyOAuthQueryString(string $rawQuery): bool
    {
        parse_str($rawQuery, $params);
        $hmac = $params['hmac'] ?? null;
        if (! $hmac) {
            return false;
        }

        $parts = explode('&', $rawQuery);
        $filtered = array_values(array_filter($parts, function (string $part) {
            return ! str_starts_with($part, 'hmac=') && ! str_starts_with($part, 'signature=');
        }));
        $message = implode('&', $filtered);

        $calculated = base64_encode(hash_hmac('sha256', $message, (string) config('shopify.api_secret'), true));

        return hash_equals($hmac, $calculated);
    }
}
