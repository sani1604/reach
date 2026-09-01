<?php

namespace App\Http\Controllers;

use App\Jobs\SubscribeWebhooks;
use App\Models\Shop;
use App\Services\ShopifyClient;
use App\Services\ShopifyRequest;
use App\Services\ShopifyWebhook;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ShopifyAuthController extends Controller
{
    /**
     * Kick off the OAuth install flow for a given store.
     */
    public function install(Request $request)
    {
        $domain = $this->normalizeDomain($request->query('shop') ?: ShopifyRequest::shopDomain($request));

        if (! $domain) {
            return view('auth.install');
        }

        $state = Str::random(40);
        session([
            'shopify.state' => $state,
            'shopify.shop'  => $domain,
        ]);

        $url = ShopifyClient::authorizeUrl(
            $domain,
            $state,
            url(config('shopify.redirect_uri', '/auth/callback'))
        );

        return redirect()->away($url);
    }

    /**
     * OAuth callback — verify HMAC, exchange code for an offline token,
     * store the shop, subscribe webhooks, drop into the embedded admin.
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

        $token = ShopifyClient::exchangeToken($domain, $code);

        if (! $token) {
            abort(500, 'Could not exchange OAuth code.');
        }

        $shop = Shop::updateOrCreate(
            ['shopify_domain' => $domain],
            [
                'access_token'  => $token,
                'installed_at'  => now(),
                'uninstalled_at' => null,
            ]
        );

        session(['shop' => $domain]);

        SubscribeWebhooks::dispatch($shop->id)->onQueue('default');

        return redirect()->away(
            "https://{$domain}/admin/apps/".config('shopify.app_handle', 'reach')
        );
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

                return redirect()->route('dashboard');
            }
        }

        return redirect()->route('auth.install', ['shop' => $domain]);
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
