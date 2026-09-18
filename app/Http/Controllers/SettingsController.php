<?php

namespace App\Http\Controllers;

use App\Jobs\PostInstallSetup;
use App\Models\Shop;
use App\Services\EventMapper;
use App\Services\OpenAiCapiClient;
use App\Services\ShopifyClient;
use Illuminate\Http\Request;
use Throwable;

class SettingsController extends Controller
{
    public function index(Request $request)
    {
        $shop = $request->attributes->get('shop');

        // Self-heal only when scopes look sufficient — otherwise Shopify just
        // returns Access denied and we spam logs on every Settings load.
        if ($shop->isInstalled() && ! $shop->webPixelActive() && $shop->hasPixelScopes()) {
            try {
                PostInstallSetup::runNow($shop);
                $shop = $shop->fresh() ?? $shop;
            } catch (Throwable $e) {
                logger()->warning('Auto web-pixel connect on settings failed', [
                    'shop'  => $shop->shopify_domain,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return view('settings', [
            'shop'              => $shop,
            'missingPixelScopes'=> $shop->missingPixelScopes(),
            'grantedScopes'     => $shop->grantedScopes(),
        ]);
    }

    public function save(Request $request)
    {
        $shop = $request->attributes->get('shop');

        $data = $this->validated($request);

        $previousPixel = $shop->pixel_id;

        $shop->update([
            'pixel_id'           => $data['pixel_id'] ?: null,
            'capi_url'           => $data['capi_url'] ?: null,
            'capi_token'         => $data['capi_token'] ?: null,
            'advertiser_api_key' => $data['advertiser_api_key'] ?: null,
        ]);

        // Always (re)activate / refresh the Customer Events web pixel so
        // Shopify admin shows Connected and the storefront starts tracking.
        $pixelOk = false;
        $pixelMessage = null;
        try {
            $outcome = app(ShopifyClient::class)->ensureWebPixelDetailed($shop->fresh());
            $pixelOk = (bool) ($outcome['ok'] ?? false);
            if ($pixelOk) {
                $pixelMessage = 'Shopify web pixel connected.';
            } else {
                $pixelMessage = 'Saved credentials, but Shopify web pixel is still disconnected: '
                    .($outcome['error'] ?? 'unknown error')
                    .'. Click “Reconnect pixel”, or run `shopify app deploy` if the extension is missing.';
            }
        } catch (Throwable $e) {
            logger()->warning('Web pixel refresh after settings save failed', [
                'shop'  => $shop->shopify_domain,
                'error' => $e->getMessage(),
            ]);
            $pixelMessage = 'Saved credentials, but could not reach Shopify to activate the web pixel: '.$e->getMessage();
        }

        // Also keep a queued retry when the OpenAI pixel id changed.
        if ($shop->pixel_id !== $previousPixel) {
            PostInstallSetup::dispatch($shop->id)->onQueue('default');
        }

        return back()->with('saved', true)->with(
            $pixelOk ? 'pixel_ok' : 'pixel_warn',
            $pixelMessage
        );
    }

    /**
     * Force-reconnect the Shopify Customer Events web pixel for this store.
     * Used when Shopify admin shows "Pixels: Disconnected".
     */
    public function reconnectPixel(Request $request)
    {
        $shop = $request->attributes->get('shop');

        if (! $shop->isInstalled()) {
            return back()->with('test_error', 'Store is not installed — reinstall the app first.');
        }

        // Detect missing scopes BEFORE calling Shopify so the merchant gets a
        // clear "update permissions" action instead of a raw GraphQL error.
        $missing = $shop->missingPixelScopes();
        if ($missing !== [] && $shop->grantedScopes() !== []) {
            return back()->with(
                'scope_error',
                'Your store token is missing required scopes: '.implode(', ', $missing)
                .'. Update app permissions so Shopify grants write_pixels + read_customer_events, then click Reconnect pixel again.'
            )->with('needs_reauth', true);
        }

        try {
            $outcome = PostInstallSetup::runNow($shop->fresh());
        } catch (Throwable $e) {
            return back()->with(
                'test_error',
                'Could not reach Shopify: '.$e->getMessage()
            );
        }

        if ($outcome['ok'] ?? false) {
            return back()->with(
                'pixel_ok',
                'Shopify web pixel connected ('.$outcome['web_pixel_id'].'). Open the storefront — events should start flowing within a minute. Refresh Shopify admin → Apps → Reach to confirm Pixels = Connected.'
            );
        }

        $error = (string) ($outcome['error'] ?? 'unknown');
        $looksLikeScope = str_contains(strtolower($error), 'access denied')
            || str_contains(strtolower($error), 'write_pixels')
            || str_contains(strtolower($error), 'read_customer_events')
            || str_contains(strtolower($error), 'access scope');

        if ($looksLikeScope) {
            return back()->with(
                'scope_error',
                'Shopify denied webPixelCreate: '.$error
                .' — the offline token still lacks write_pixels + read_customer_events. '
                .'Deploy scopes (`shopify app deploy`), then update permissions / reinstall once so the store re-grants them.'
            )->with('needs_reauth', true);
        }

        return back()->with(
            'test_error',
            'Could not activate the web pixel: '.$error
            .'. Make sure the Reach Pixel extension is deployed (`shopify app deploy`).'
        );
    }

    /**
     * Force a top-level OAuth re-authorize so the store grants the latest
     * scopes from shopify.app.toml (write_pixels + read_customer_events).
     */
    public function updatePermissions(Request $request)
    {
        $shop = $request->attributes->get('shop');
        $domain = $shop->shopify_domain;

        // Clear the cached offline token so the next OAuth callback replaces it
        // with a token that includes the new scopes. Keep OpenAI credentials.
        $shop->update([
            'access_token'              => null,
            'refresh_token'             => null,
            'token_expires_at'          => null,
            'refresh_token_expires_at'  => null,
            'token_scopes'              => null,
            'web_pixel_id'              => null,
            'uninstalled_at'            => null,
        ]);

        session()->forget('shop');

        return redirect()->route('auth.install', ['shop' => $domain]);
    }

    /**
     * Send a TestEvent to the configured Conversions API endpoint using the
     * submitted values (not yet saved), and optionally probe the Advertiser API.
     */
    public function testCapi(Request $request)
    {
        $shop = $request->attributes->get('shop');
        $data = $this->validated($request);

        $probe = new Shop([
            'shopify_domain'     => $shop->shopify_domain,
            'pixel_id'           => $data['pixel_id'] ?: $shop->pixel_id,
            'capi_url'           => $data['capi_url'] ?: $shop->capi_url,
            'capi_token'         => $data['capi_token'] ?: $shop->capi_token,
            'advertiser_api_key' => $data['advertiser_api_key'] ?: $shop->advertiser_api_key,
        ]);

        if (! $probe->capi_token) {
            return back()->withInput()->with(
                'test_error',
                'Add a Conversions API key first, then test.'
            );
        }

        if (! $probe->pixelConfigured()) {
            return back()->withInput()->with(
                'test_error',
                'Add your OpenAI Ads Pixel ID first, then test.'
            );
        }

        $event = app(EventMapper::class)->build('TestEvent', [
            'event_time'        => time(),
            'event_id'          => 'reach-test-'.time(),
            'source_url'        => 'https://'.$shop->shopify_domain,
            'shop_domain'       => $shop->shopify_domain,
            'custom_event_name' => 'reach_test_connection',
            'value'             => 1.00,
            'currency'          => 'USD',
        ]);

        // validate_only=true so the test never pollutes the merchant's reporting.
        $result = app(OpenAiCapiClient::class)->send($probe, $event, validateOnly: true);

        $messages = [];

        if ($result['ok']) {
            $messages[] = 'Conversions API OK — OpenAI accepted a validate-only test event.';
        } else {
            $detail = is_array($result['body'] ?? null)
                ? json_encode(array_slice((array) $result['body'], 0, 5))
                : ($result['error'] ?? 'unknown error');

            return back()->withInput()->with(
                'test_error',
                'Conversions API failed (HTTP '.($result['status'] ?? 'n/a').'): '.$detail
            );
        }

        // Optional Advertiser API key check (Ads Manager key).
        if ($probe->advertiser_api_key) {
            $adv = app(OpenAiCapiClient::class)->probeAdvertiserKey($probe);
            if ($adv['ok']) {
                $name = is_array($adv['body'] ?? null)
                    ? ($adv['body']['name'] ?? null)
                    : null;
                $messages[] = 'Advertiser API key OK'
                    .($name ? ' (account: '.$name.')' : '').'.';
            } else {
                $messages[] = 'Advertiser API key failed (HTTP '
                    .($adv['status'] ?? 'n/a').') — CAPI still works; fix the Ads Manager key if you need account tooling.';
            }
        }

        // Surface Shopify pixel status in the same toast so merchants know
        // whether storefront events will actually fire.
        if (! $shop->webPixelActive()) {
            $messages[] = 'Warning: Shopify web pixel is still disconnected — click “Reconnect pixel”.';
        }

        return back()->withInput()->with('test_ok', implode(' ', $messages));
    }

    protected function validated(Request $request): array
    {
        $data = $request->validate([
            'pixel_id'           => ['nullable', 'string', 'max:255'],
            'capi_url'           => ['nullable', 'string', 'max:500'],
            'capi_token'         => ['nullable', 'string', 'max:5000'],
            'advertiser_api_key' => ['nullable', 'string', 'max:5000'],
        ]);

        // SSRF: only allow blank (use default) or HTTPS OpenAI Ads hosts.
        if (! empty($data['capi_url'])) {
            $data['capi_url'] = $this->sanitizeCapiUrl((string) $data['capi_url']);
        }

        // Strip control characters from secrets / ids.
        foreach (['pixel_id', 'capi_token', 'advertiser_api_key'] as $key) {
            if (! empty($data[$key]) && is_string($data[$key])) {
                $data[$key] = trim(preg_replace('/[\x00-\x1f\x7f]/', '', $data[$key]) ?? $data[$key]);
            }
        }

        return $data;
    }

    /**
     * Reject non-HTTPS and non-OpenAI CAPI endpoints (SSRF protection).
     */
    protected function sanitizeCapiUrl(string $url): ?string
    {
        $url = trim($url);
        if ($url === '') {
            return null;
        }

        $parts = parse_url($url);
        if (! is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));

        if ($scheme !== 'https' || $host === '') {
            // Invalid override — fall back to app default (null = use config).
            return null;
        }

        if (
            $host === 'localhost'
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')
            || filter_var($host, FILTER_VALIDATE_IP)
        ) {
            return null;
        }

        $allowed = ['bzr.openai.com', 'api.ads.openai.com', 'api.openai.com'];
        $defaultHost = parse_url((string) config('ads.capi_url'), PHP_URL_HOST);
        if (is_string($defaultHost) && $defaultHost !== '') {
            $allowed[] = strtolower($defaultHost);
        }

        $ok = false;
        foreach ($allowed as $h) {
            if ($host === $h || str_ends_with($host, '.'.$h)) {
                $ok = true;
                break;
            }
        }

        return $ok ? $url : null;
    }
}
