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

        // Self-heal: if the store is installed but the Customer Events pixel
        // was never activated (common when the queue worker was offline at
        // install time), try once on page load so the merchant doesn't stay
        // stuck on "Pixels: Disconnected".
        if ($shop->isInstalled() && ! $shop->webPixelActive()) {
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

        return view('settings', compact('shop'));
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

        return back()->with(
            'test_error',
            'Could not activate the web pixel: '.($outcome['error'] ?? 'unknown')
            .'. Make sure the Reach Pixel extension is deployed (`shopify app deploy`) and the app has the write_pixels scope.'
        );
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
        return $request->validate([
            'pixel_id'           => ['nullable', 'string', 'max:255'],
            'capi_url'           => ['nullable', 'url', 'max:500'],
            'capi_token'         => ['nullable', 'string', 'max:5000'],
            'advertiser_api_key' => ['nullable', 'string', 'max:5000'],
        ]);
    }
}
