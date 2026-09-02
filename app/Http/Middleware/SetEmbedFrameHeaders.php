<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Embedded apps must be frameable by the Shopify admin. Shopify's app review
 * expects a Content-Security-Policy `frame-ancestors` restricted to Shopify
 * origins (instead of a blanket allow) and no conflicting X-Frame-Options.
 */
class SetEmbedFrameHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->remove('X-Frame-Options');

        $response->headers->set(
            'Content-Security-Policy',
            "frame-ancestors https://*.myshopify.com https://admin.shopify.com https://admin.spin.dev;"
        );

        return $response;
    }
}
