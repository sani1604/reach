<?php

namespace App\Http\Middleware;

use App\Models\Shop;
use App\Services\SessionToken;
use App\Services\ShopifyRequest;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticate an embedded-app request (2026 pattern — session tokens are
 * mandatory for embedded apps; cookies in the admin iframe are third-party
 * and can be blocked):
 *
 *  1. App Bridge session token from `Authorization: Bearer` (fetch/XHR)
 *  2. `X-Shopify-Session-Token` header
 *  3. `id_token` query param (link navigations) or form field (POSTs)
 *  4. Cookie-session fallback (works when third-party cookies are allowed
 *     and for the local demo)
 *  5. `?shop=` param / `X-Shopify-Shop-Domain` header for already-trusted
 *     contexts
 *
 * On failure we never send the merchant to the OAuth screen from inside the
 * iframe (Shopify blocks OAuth in iframes — that caused the "installation
 * page" redirect loop). Instead the embedded boot page re-establishes a
 * session token and returns the merchant to where they were.
 */
class VerifyShopifyRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $shop = $this->resolveFromSessionToken($request)
            ?? $this->resolveFromSession($request);

        if (! $shop) {
            return $this->fail($request);
        }

        session(['shop' => $shop->shopify_domain]);
        $request->attributes->set('shop', $shop);

        return $next($request);
    }

    protected function resolveFromSessionToken(Request $request): ?Shop
    {
        $jwt = $request->bearerToken()
            ?: $request->header('X-Shopify-Session-Token')
            ?: ($request->query('id_token') ?: $request->input('id_token'));

        if (! $jwt || ! is_string($jwt)) {
            return null;
        }

        $claims = app(SessionToken::class)->verify($jwt);
        if (! $claims) {
            return null;
        }

        $domain = app(SessionToken::class)->shopDomain($claims);
        if (! $domain) {
            return null;
        }

        $shop = Shop::where('shopify_domain', $domain)->first();

        return ($shop && $shop->isInstalled()) ? $shop : null;
    }

    protected function resolveFromSession(Request $request): ?Shop
    {
        $domain = ShopifyRequest::shopDomain($request);
        if (! $domain) {
            return null;
        }

        // A bare ?shop= query param is only trusted when the session already
        // belongs to that store, when Shopify itself vouches for the domain
        // (X-Shopify-Shop-Domain), or on the app's first load inside the
        // admin iframe (referrer is the admin origin). This keeps other
        // stores' dashboards from being rendered to arbitrary visitors.
        if ($request->session()->get('shop') !== $domain && ! $request->header('X-Shopify-Shop-Domain')) {
            $adminHost = parse_url((string) $request->headers->get('referer', ''), PHP_URL_HOST);

            $fromShopify = $adminHost && preg_match(
                '/(^|\.)(admin\.shopify\.com|myshopify\.com)$/i',
                (string) $adminHost
            );

            if (! $fromShopify) {
                return null;
            }
        }

        $shop = Shop::where('shopify_domain', $domain)->first();

        return ($shop && $shop->isInstalled()) ? $shop : null;
    }

    protected function fail(Request $request): Response
    {
        if ($request->expectsJson() || $request->ajax()) {
            abort(401, 'Unauthenticated.');
        }

        $domain = ShopifyRequest::shopDomain($request);

        if ($domain) {
            // Store is (was) installed: re-enter through the embedded boot
            // page instead of bouncing the merchant to an install screen.
            // The boot page re-establishes a session token and returns the
            // merchant to the exact page they were on.
            return redirect()->route('auth.boot', [
                'shop' => $domain,
                'to'   => '/'.ltrim($request->path(), '/'),
            ]);
        }

        return redirect()->route('auth.install');
    }
}
