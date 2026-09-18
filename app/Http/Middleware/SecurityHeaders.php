<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline HTTP security headers for the Reach app host.
 * Frame-ancestors for the embedded admin are set separately by
 * SetEmbedFrameHeaders (must not conflict with X-Frame-Options).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-XSS-Protection', '0'); // modern browsers; rely on CSP
        $response->headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=(), payment=(), usb=()'
        );

        // HSTS only over HTTPS (and not on local).
        if ($request->secure() && ! app()->environment('local', 'testing')) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains'
            );
        }

        // Avoid caching authenticated HTML responses.
        $hasShop = $request->attributes->has('shop');
        try {
            $hasShop = $hasShop || ($request->hasSession() && $request->session()->has('shop'));
        } catch (\Throwable) {
            // Session may be unavailable on some API routes.
        }

        if ($hasShop && ! $response->headers->has('Cache-Control')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
