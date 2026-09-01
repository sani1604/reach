<?php

namespace App\Services;

use Illuminate\Http\Request;

class ShopifyRequest
{
    /**
     * Resolve the requesting shop's *.myshopify.com domain from the embedded
     * app header, the ?shop= query param, or the session — in that order.
     */
    public static function shopDomain(Request $request): ?string
    {
        $domain = $request->header('X-Shopify-Shop-Domain')
            ?: $request->query('shop')
            ?: session('shop');

        if (! $domain) {
            return null;
        }

        // Shopify sends a bare handle (e.g. "mystore") in the embedded header.
        if (! str_contains($domain, '.') && ! str_contains($domain, ':')) {
            $domain .= '.myshopify.com';
        }

        return strtolower($domain);
    }
}
