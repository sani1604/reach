<?php

namespace App\Services;

class ShopifyWebhook
{
    /**
     * Verify a webhook request. Shopify signs the raw JSON body with HMAC-SHA256
     * and ships it in the X-Shopify-Hmac-Sha256 header.
     *
     * Multi-app: tries every configured Partner secret.
     */
    public static function verify(string $rawBody, ?string $hmacHeader): bool
    {
        return ShopifyApp::appKeyFromWebhookHmac($rawBody, $hmacHeader) !== null;
    }

    /**
     * Verify the OAuth callback query string (hmac + signature params removed,
     * sorted alphabetically, and hashed with HMAC-SHA256 hex digest per Shopify spec).
     *
     * Multi-app: tries each secret and binds the matching app key.
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
        $message = http_build_query($params);

        $keys = array_values(array_unique(array_filter([
            ShopifyApp::key(),
            ...ShopifyApp::configuredKeys(),
        ])));

        foreach ($keys as $appKey) {
            $secret = ShopifyApp::apiSecret($appKey);
            if (! $secret) {
                continue;
            }
            $calculated = hash_hmac('sha256', $message, $secret);
            if (hash_equals($hmac, $calculated)) {
                ShopifyApp::setKey($appKey);

                return true;
            }
        }

        $fallback = (string) config('shopify.api_secret');
        if ($fallback !== '') {
            $calculated = hash_hmac('sha256', $message, $fallback);

            return hash_equals($hmac, $calculated);
        }

        return false;
    }
}
