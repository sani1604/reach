<?php

namespace App\Http\Middleware;

use App\Models\Shop;
use App\Services\SessionToken;
use App\Services\ShopDomain;
use App\Services\ShopifyApp;
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
 *
 * Spoofable client headers (`X-Shopify-Shop-Domain`, `Referer`) are NEVER
 * enough on their own to authorize a request — that was a VAPT finding.
 *
 * On failure we never send the merchant to the OAuth screen from inside the
 * iframe (Shopify blocks OAuth in iframes). Instead the embedded boot page
 * re-establishes a session token and returns the merchant to where they were.
 */
class VerifyShopifyRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        $shop = $this->resolveFromSessionToken($request)
            ?? $this->resolveFromTrustedSession($request);

        if (! $shop) {
            return $this->fail($request);
        }

        // Optional: if the client also sent a shop hint, it must match the
        // authenticated domain (prevents confused-deputy token reuse).
        $hint = ShopDomain::normalize(
            $request->query('shop')
                ?: $request->input('shop')
                ?: $request->header('X-Shopify-Shop-Domain')
        );
        if ($hint && $hint !== $shop->shopify_domain) {
            return $this->fail($request);
        }

        session([
            'shop'        => $shop->shopify_domain,
            'shopify_app' => $shop->appKey(),
        ]);
        ShopifyApp::setKey($shop->appKey());
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
        $domain = ShopDomain::normalize($domain);
        if (! $domain) {
            return null;
        }

        $shop = Shop::findForApp($domain);

        return ($shop && $shop->isInstalled()) ? $shop : null;
    }

    /**
     * Cookie/session auth only — never trust bare ?shop= or spoofable headers.
     */
    protected function resolveFromTrustedSession(Request $request): ?Shop
    {
        $sessionDomain = ShopDomain::normalize(
            is_string($request->session()->get('shop'))
                ? $request->session()->get('shop')
                : null
        );

        if (! $sessionDomain) {
            return null;
        }

        $sessionApp = $request->session()->get('shopify_app');
        if (is_string($sessionApp) && ShopifyApp::isKnown($sessionApp)) {
            ShopifyApp::setKey($sessionApp);
        }

        // If the request also carries a shop query/body, it must match session.
        $hint = ShopDomain::normalize(
            $request->query('shop') ?: $request->input('shop')
        );
        if ($hint && $hint !== $sessionDomain) {
            return null;
        }

        $shop = Shop::findForApp($sessionDomain);

        return ($shop && $shop->isInstalled()) ? $shop : null;
    }

    protected function fail(Request $request): Response
    {
        if ($request->expectsJson() || $request->ajax()) {
            abort(401, 'Unauthenticated.');
        }

        $domain = ShopDomain::normalize(ShopifyRequest::shopDomain($request));

        if ($domain) {
            return redirect()->route('auth.boot', [
                'shop' => $domain,
                'to'   => ShopDomain::safeAppPath('/'.ltrim($request->path(), '/')),
            ]);
        }

        return redirect()->route('auth.install');
    }
}
