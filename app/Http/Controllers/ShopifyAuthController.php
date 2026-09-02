<?php

namespace App\Http\Controllers;

use App\Jobs\PostInstallSetup;
use App\Models\Shop;
use App\Services\SessionToken;
use App\Services\ShopifyClient;
use App\Services\ShopifyRequest;
use App\Services\ShopifyWebhook;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ShopifyAuthController extends Controller
{
    /**
     * Embedded entry (application_url). Shopify loads the app inside the
     * admin iframe with ?shop=&host=. OAuth redirects are blocked inside
     * iframes, so this renders a tiny boot page that authenticates with an
     * App Bridge session token (token exchange / managed installation) and
     * then navigates to the requested page.
     */
    public function boot(Request $request)
    {
        $domain = $this->normalizeDomain(
            $request->query('shop') ?: ShopifyRequest::shopDomain($request)
        );

        if (! $domain) {
            return redirect()->route('auth.install');
        }

        return view('auth.embedded', [
            'shop'     => $domain,
            'host'     => (string) $request->query('host', ''),
            'target'   => (string) $request->query('to', '/dashboard'),
        ]);
    }

    /**
     * Called by the boot page (and by the app when a session expires):
     * verifies the App Bridge session token and, when the store has no
     * offline token yet, exchanges it for one (Shopify managed installation
     * + token exchange — the 2026 embedded-app flow; no redirects).
     */
    public function tokenExchange(Request $request): JsonResponse
    {
        $idToken = (string) ($request->bearerToken() ?: $request->input('id_token', ''));
        $domain = $this->normalizeDomain($request->input('shop'));

        if (! $idToken || ! $domain) {
            return response()->json(['ok' => false, 'reason' => 'bad_request'], 400);
        }

        $claims = app(SessionToken::class)->verify($idToken);
        $tokenShop = $claims ? app(SessionToken::class)->shopDomain($claims) : null;

        if (! $claims || $tokenShop !== $domain) {
            return response()->json(['ok' => false, 'reason' => 'invalid_token'], 401);
        }

        $shop = Shop::where('shopify_domain', $domain)->first();

        // Token exchange requires the app to be installed (Shopify managed
        // installation registers the app without calling us). A missing or
        // expired token means we have to (re)install — fall back to the
        // top-level authorization code grant.
        $needsSetup = false;

        if (! $shop || ! $shop->isInstalled() || $shop->tokenNeedsRefresh()) {
            $tokens = ShopifyClient::exchangeIdToken($domain, $idToken);

            if (! $tokens) {
                return response()->json([
                    'ok'       => false,
                    'reason'   => 'not_installed',
                    'install'  => route('auth.install', ['shop' => $domain]),
                ], 200);
            }

            $shop = Shop::updateOrCreate(
                ['shopify_domain' => $domain],
                array_merge($this->tokenAttributes($tokens), [
                    'installed_at'   => $shop->installed_at ?? now(),
                    'uninstalled_at' => null,
                ])
            );

            $needsSetup = true;
        }

        // First contact with the store: register webhooks + activate the
        // web pixel extension (queued so the boot stays fast).
        if ($needsSetup || ! $shop->pixelConfigured()) {
            PostInstallSetup::dispatch($shop->id)->onQueue('default');
        }

        session(['shop' => $domain]);

        return response()->json(['ok' => true]);
    }

    /**
     * Top-level OAuth install (public landing form / non-managed installs).
     * Renders a redirect page so the authorize navigation always happens in
     * the top-level window — Shopify blocks OAuth inside the admin iframe.
     */
    public function install(Request $request)
    {
        $domain = $this->normalizeDomain($request->query('shop') ?: ShopifyRequest::shopDomain($request));

        if (! $domain) {
            return view('auth.install');
        }

        // Already installed: skip the consent screen entirely.
        $shop = Shop::where('shopify_domain', $domain)->first();
        if ($shop && $shop->isInstalled()) {
            session(['shop' => $domain]);

            return redirect()->route('dashboard', ['shop' => $domain]);
        }

        $state = Str::random(40);
        session([
            'shopify.state' => $state,
            'shopify.shop'  => $domain,
        ]);
        session()->save();

        $authorizeUrl = ShopifyClient::authorizeUrl(
            $domain,
            $state,
            url(config('shopify.redirect_uri', '/auth/callback'))
        );

        return view('auth.redirecting', ['url' => $authorizeUrl, 'top' => true]);
    }

    /**
     * OAuth callback — verify HMAC, exchange the code for an *expiring*
     * offline token (2026 policy), store the shop, queue webhooks + pixel,
     * then return the merchant to the app inside the admin.
     */
    public function callback(Request $request)
    {
        $domain = $this->normalizeDomain($request->query('shop'));
        $code = $request->query('code');
        $state = $request->query('state');
        $rawQuery = $request->server('QUERY_STRING', '');

        if (! $domain || ! $code || ! $state || $state !== session('shopify.state')) {
            abort(403, 'Invalid state.');
        }

        if (! ShopifyWebhook::verifyOAuthQueryString($rawQuery)) {
            abort(403, 'HMAC verification failed.');
        }

        $tokens = ShopifyClient::exchangeToken($domain, $code);

        if (! $tokens) {
            abort(500, 'Could not exchange OAuth code.');
        }

        $shop = Shop::updateOrCreate(
            ['shopify_domain' => $domain],
            array_merge($this->tokenAttributes($tokens), [
                'installed_at'   => now(),
                'uninstalled_at' => null,
            ])
        );

        session(['shop' => $domain]);

        PostInstallSetup::dispatch($shop->id)->onQueue('default');

        // Return the merchant to the app inside the Shopify admin. The admin
        // app URL has no sub-path — /apps/{handle}/{anything} renders the
        // admin's "this page doesn't exist" screen.
        $host = (string) $request->query('host', '');
        $handle = config('shopify.app_handle');

        $adminUrl = $host
            ? 'https://'.base64_decode($host)."/apps/{$handle}"
            : "https://{$domain}/admin/apps/{$handle}";

        return view('auth.redirecting', ['url' => $adminUrl, 'top' => true]);
    }

    /**
     * Login entry (also used for the local demo). In production the only
     * valid path is through the embedded install flow.
     */
    public function login(Request $request)
    {
        $domain = $this->normalizeDomain($request->query('shop'));

        if ($domain) {
            $shop = Shop::where('shopify_domain', $domain)->first();
            if ($shop && $shop->isInstalled()) {
                session(['shop' => $domain]);

                return redirect()->route('dashboard', ['shop' => $domain]);
            }
        }

        return redirect()->route('auth.install', ['shop' => $domain]);
    }

    protected function tokenAttributes(array $tokens): array
    {
        return app(ShopifyClient::class)->tokenAttributes($tokens);
    }

    protected function normalizeDomain(?string $domain): ?string
    {
        if (! $domain) {
            return null;
        }

        $domain = strtolower(trim($domain));
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = rtrim($domain, '/');

        if (! str_contains($domain, '.') && ! str_contains($domain, ':')) {
            $domain .= '.myshopify.com';
        }

        return $domain;
    }
}
