<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Services\EventForwarder;
use App\Services\VisitorBridge;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ApiController extends Controller
{
    /**
     * Public pixel config for the storefront tracker.
     */
    public function pixelConfig(Request $request)
    {
        $domain = strtolower((string) $request->query('shop'));
        if (! $domain) {
            return response()->json(['enabled' => false]);
        }

        $shop = Shop::where('shopify_domain', $domain)->first();

        if (! $shop || ! $shop->isInstalled() || ! $shop->pixelConfigured()) {
            return response()->json(['enabled' => false, 'shop' => $domain]);
        }

        return response()->json([
            'enabled'           => true,
            'shop'              => $domain,
            'pixel_id'          => $shop->pixel_id,
            'browser_pixel_url' => config('ads.browser_pixel_url'),
            'events'            => ['PageView', 'ViewContent', 'AddToCart', 'InitiateCheckout', 'Purchase'],
            'version'           => '1.0',
        ]);
    }

    /**
     * Receive a browser event from the storefront pixel (sendBeacon POST).
     */
    public function track(Request $request)
    {
        $domain = strtolower((string) $request->input('shop'));
        $eventName = $request->input('event') ?: $request->input('event_name');
        $data = $request->input('data', []);

        if (! $domain || ! $eventName) {
            return response()->json(['ok' => false, 'error' => 'shop and event are required'], 400);
        }

        $shop = Shop::where('shopify_domain', $domain)->first();
        if (! $shop || ! $shop->isInstalled()) {
            return response()->json(['ok' => false], 404);
        }

        $standard = $this->standardName((string) $eventName);

        // Purchase is server-authoritative (order webhooks); skip browser
        // duplicates so revenue is never double-counted.
        if ($standard === 'Purchase') {
            return response()->json(['ok' => true, 'skipped' => true]);
        }

        if (! is_array($data)) {
            $data = [];
        }
        $data['event_time'] = (int) ($data['event_time'] ?? time());
        $data['event_id'] = (string) ($data['event_id'] ?? Str::uuid());

        // Fold click IDs (fbc/fbp) into the stored payload for cross-device matching.
        $userData = $request->input('user_data', []);
        if (is_array($userData) && $userData) {
            $data['user_data'] = array_merge($data['user_data'] ?? [], $userData);
        }

        // Flatten the OpenAI-style custom_data the tracker may pass through.
        if (! empty($data['custom_data']) && is_array($data['custom_data'])) {
            $data = array_merge($data, $data['custom_data']);
        }

        // Update the visitor identity bridge (vid + click ids + email/phone).
        app(VisitorBridge::class)->upsert($shop, [
            'vid'       => $request->input('vid'),
            'user_data' => $data['user_data'] ?? [],
            'email'     => $data['email'] ?? null,
            'phone'     => $data['phone'] ?? null,
        ]);

        app(EventForwarder::class)->recordBrowser($shop, $standard, $data);

        return response()->json(['ok' => true], 202);
    }

    /**
     * Order-status enrichment: the pixel calls this with order + click IDs so
     * a recorded Purchase can be enriched and re-forwarded to the CAPI.
     */
    public function enrich(Request $request)
    {
        $domain = strtolower((string) $request->input('shop'));
        $shop = $domain ? Shop::where('shopify_domain', $domain)->first() : null;

        // The checkout UI extension doesn't know the shop domain (checkout
        // sandbox). Resolve the store from a previously recorded Purchase
        // event for this order instead.
        if (! $shop) {
            $orderId = preg_replace('/\D/', '', (string) $request->input('data.order_id'));

            $shop = $orderId
                ? Shop::whereHas('events', fn ($q) => $q->where('order_id', $orderId))->first()
                : null;
        }

        if (! $shop || ! $shop->isInstalled()) {
            return response()->json(['ok' => false], 404);
        }

        $data = $request->input('data', []);
        if (! is_array($data)) {
            $data = [];
        }
        $vid = $request->input('vid');

        $bridge = app(VisitorBridge::class);

        $bridge->upsert($shop, [
            'vid'       => $vid,
            'user_data' => ['fbc' => $data['fbc'] ?? null, 'fbp' => $data['fbp'] ?? null],
            'email'     => $data['email'] ?? null,
            'phone'     => $data['phone'] ?? null,
            'order_id'  => $data['order_id'] ?? null,
        ]);

        $enriched = $bridge->enrichPurchase($shop, $data);

        return response()->json(['ok' => true, 'enriched' => $enriched]);
    }

    protected function standardName(string $name): string
    {
        $map = [
            'page_viewed'            => 'PageView',
            'pageview'               => 'PageView',
            'viewcontent'            => 'ViewContent',
            'product_viewed'         => 'ViewContent',
            'addtocart'              => 'AddToCart',
            'product_added_to_cart'  => 'AddToCart',
            'initiatecheckout'       => 'InitiateCheckout',
            'checkout_started'       => 'InitiateCheckout',
            'purchase'               => 'Purchase',
        ];

        return $map[strtolower($name)] ?? $name;
    }
}
