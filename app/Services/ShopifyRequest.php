<?php

namespace App\Services;

use Illuminate\Http\Request;

class ShopifyRequest
{
    /**
     * Resolve a candidate shop domain from the request.
     *
     * Order: session (trusted) → query/body shop → X-Shopify-Shop-Domain header.
     * Callers that authorize access MUST still verify a session token or a
     * matching session cookie — headers alone are spoofable.
     */
    public static function shopDomain(Request $request): ?string
    {
        $candidates = [
            session('shop'),
            $request->query('shop'),
            $request->input('shop'),
            $request->header('X-Shopify-Shop-Domain'),
        ];

        foreach ($candidates as $candidate) {
            $domain = ShopDomain::normalize(is_string($candidate) ? $candidate : null);
            if ($domain) {
                return $domain;
            }
        }

        return null;
    }
}
