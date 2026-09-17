<?php

namespace App\Http\Controllers;

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

        // Push the new OpenAI Pixel ID into the Customer Events web pixel so
        // the storefront dual-fires with the correct id immediately.
        if ($shop->pixel_id !== $previousPixel || ! $shop->web_pixel_id) {
            try {
                app(ShopifyClient::class)->ensureWebPixel($shop->fresh());
            } catch (Throwable $e) {
                logger()->warning('Web pixel refresh after settings save failed', [
                    'shop'  => $shop->shopify_domain,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return back()->with('saved', true);
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
            'event_time'     => time(),
            'event_id'       => 'reach-test-'.time(),
            'source_url'     => 'https://'.$shop->shopify_domain,
            'shop_domain'    => $shop->shopify_domain,
            'custom_event_name' => 'reach_test_connection',
            'value'          => 1.00,
            'currency'       => 'USD',
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
