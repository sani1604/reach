<?php

namespace App\Http\Middleware;

use App\Models\Shop;
use App\Services\SessionToken;
use App\Services\ShopifyRequest;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyShopifyRequest
{
    /**
     * Authenticate an embedded-app request. Prefers an App Bridge session
     * token (Authorization: Bearer), then falls back to the cookie session.
     * Redirects to the install page when there is no valid identity.
     */
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
        $jwt = $request->bearerToken() ?: $request->header('X-Shopify-Session-Token');
        if (! $jwt) {
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

        $shop = Shop::where('shopify_domain', $domain)->first();

        return ($shop && $shop->isInstalled()) ? $shop : null;
    }

    protected function fail(Request $request): Response
    {
        if ($request->expectsJson()) {
            abort(401, 'Unauthenticated.');
        }

        return redirect()->route('auth.install');
    }
}
