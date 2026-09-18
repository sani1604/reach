<?php

namespace App\Http\Middleware;

use App\Services\SessionToken;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken as Middleware;
use Illuminate\Http\Request;

/**
 * Embedded Shopify admin iframes often block third-party cookies, so the
 * Laravel XSRF cookie is unreliable. When a valid App Bridge session JWT is
 * present we treat it as the anti-CSRF proof (attacker cannot mint it without
 * the app secret + a live admin session).
 */
class VerifyCsrfToken extends Middleware
{
    /**
     * URIs that always skip CSRF (HMAC or public pixel endpoints).
     *
     * @var list<string>
     */
    protected $except = [
        'webhooks',
        'webhooks/*',
        'auth/token-exchange',
        // Public storefront pixel (CORS) — no session cookies.
        'api/*',
    ];

    protected function tokensMatch(Request $request): bool
    {
        if (parent::tokensMatch($request)) {
            return true;
        }

        // Fallback: valid Shopify session token in Authorization / id_token.
        $jwt = $request->bearerToken()
            ?: $request->header('X-Shopify-Session-Token')
            ?: ($request->input('id_token') ?: $request->query('id_token'));

        if (! is_string($jwt) || $jwt === '') {
            return false;
        }

        return app(SessionToken::class)->verify($jwt) !== null;
    }
}
