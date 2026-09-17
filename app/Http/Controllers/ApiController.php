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
     * Public pixel config for the storefront tracker / web pixel extension.
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
            'capi_ready'        => $shop->capiReady(),
            'events'            => [
                'PageView'         => 'page_viewed',
                'ViewContent'      => 'contents_viewed',
                'AddToCart'        => 'items_added',
                'InitiateCheckout' => 'checkout_started',
                'Purchase'         => 'order_created',
            ],
            'version'           => '2.0',
        ]);
    }

    /**
     * Receive a browser event from the storefront pixel / web pixel extension.
     * Records it on the dashboard and forwards it server-side to OpenAI CAPI.
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
        // duplicates so revenue is never double-counted on the dashboard.
        // (The browser Measurement Pixel may still fire order_created for
        // OpenAI-side dual delivery with the same event_id.)
        if ($standard === 'Purchase') {
            return response()->json(['ok' => true, 'skipped' => true]);
        }

        if (! is_array($data)) {
            $data = [];
        }

        $data['event_time'] = (int) ($data['event_time'] ?? $request->input('event_time') ?? time());
        $data['event_id'] = (string) (
            $data['event_id']
            ?? $request->input('event_id')
            ?? Str::uuid()
        );

        // Fold click IDs + OpenAI attribution ids into the stored payload.
        $userData = $request->input('user_data', []);
        if (! is_array($userData)) {
            $userData = [];
        }

        $identityKeys = [
            'fbc', 'fbp', 'oppref', 'obref', 'email', 'phone',
            'oai_click_id', 'chatgpt_aid', 'oai_cid',
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
            'city', 'region', 'state', 'country', 'postal_code', 'zip',
            'first_name', 'last_name', 'whatsapp_id', 'checkout_provider',
        ];
        foreach ($identityKeys as $key) {
            if ($request->filled($key) && empty($userData[$key])) {
                $userData[$key] = $request->input($key);
            }
            if (! empty($data[$key]) && empty($userData[$key])) {
                $userData[$key] = $data[$key];
            }
        }

        // Promote alternate OpenAI click-id names onto oppref.
        if (empty($userData['oppref'])) {
            foreach (['oai_click_id', 'chatgpt_aid', 'oai_cid'] as $alt) {
                if (! empty($userData[$alt])) {
                    $userData['oppref'] = $userData[$alt];
                    break;
                }
            }
        }

        // IP / UA for server-side matching (pixel runtime may not send these).
        if (empty($userData['ip_address']) && empty($userData['client_ip_address'])) {
            $userData['client_ip_address'] = $request->ip();
        }
        if (empty($userData['user_agent']) && empty($userData['client_user_agent'])) {
            $userData['client_user_agent'] = (string) $request->userAgent();
        }

        if ($userData) {
            $data['user_data'] = array_merge($data['user_data'] ?? [], $userData);
        }

        // Promote oppref to top-level for the CAPI mapper.
        if (! empty($data['user_data']['oppref']) && empty($data['oppref'])) {
            $data['oppref'] = $data['user_data']['oppref'];
        }

        // Flatten the OpenAI-style custom_data the tracker may pass through.
        if (! empty($data['custom_data']) && is_array($data['custom_data'])) {
            $data = array_merge($data, $data['custom_data']);
        }

        // Source URL for web events (required by OpenAI CAPI).
        if (empty($data['source_url']) && empty($data['url'])) {
            $data['source_url'] = $request->input('url')
                ?: $request->headers->get('Referer')
                ?: ('https://'.$shop->shopify_domain);
        }
        $data['shop_domain'] = $shop->shopify_domain;

        // Update the visitor identity bridge (vid + click ids + email/phone).
        app(VisitorBridge::class)->upsert($shop, [
            'vid'       => $request->input('vid'),
            'user_data' => $data['user_data'] ?? [],
            'email'     => $data['email'] ?? ($data['user_data']['email'] ?? null),
            'phone'     => $data['phone'] ?? ($data['user_data']['phone'] ?? null),
            'oppref'    => $data['oppref'] ?? null,
            'obref'     => $data['user_data']['obref'] ?? null,
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

        // Lift top-level attribution ids into data.
        foreach (['oppref', 'obref', 'fbc', 'fbp', 'email', 'phone'] as $key) {
            if ($request->filled($key) && empty($data[$key])) {
                $data[$key] = $request->input($key);
            }
        }

        $bridge = app(VisitorBridge::class);

        $bridge->upsert($shop, [
            'vid'       => $vid,
            'user_data' => [
                'fbc'    => $data['fbc'] ?? null,
                'fbp'    => $data['fbp'] ?? null,
                'oppref' => $data['oppref'] ?? null,
                'obref'  => $data['obref'] ?? null,
            ],
            'email'     => $data['email'] ?? null,
            'phone'     => $data['phone'] ?? null,
            'order_id'  => $data['order_id'] ?? null,
            'oppref'    => $data['oppref'] ?? null,
            'obref'     => $data['obref'] ?? null,
        ]);

        $enriched = $bridge->enrichPurchase($shop, array_merge($data, [
            'vid' => $vid,
        ]));

        return response()->json(['ok' => true, 'enriched' => $enriched]);
    }

    protected function standardName(string $name): string
    {
        $map = [
            // Shopify Customer Events
            'page_viewed'            => 'PageView',
            'product_viewed'         => 'ViewContent',
            'product_added_to_cart'  => 'AddToCart',
            'checkout_started'       => 'InitiateCheckout',
            'checkout_completed'     => 'Purchase',
            'payment_info_submitted' => 'AddPaymentInfo',
            // OpenAI Ads taxonomy
            'pageview'               => 'PageView',
            'page_view'              => 'PageView',
            'viewcontent'            => 'ViewContent',
            'contents_viewed'        => 'ViewContent',
            'addtocart'              => 'AddToCart',
            'items_added'            => 'AddToCart',
            'initiatecheckout'       => 'InitiateCheckout',
            'addpaymentinfo'         => 'AddPaymentInfo',
            'add_payment_info'       => 'AddPaymentInfo',
            'purchase'               => 'Purchase',
            'order_created'          => 'Purchase',
            // Internal names (pass-through)
            'PageView'               => 'PageView',
            'ViewContent'            => 'ViewContent',
            'AddToCart'              => 'AddToCart',
            'InitiateCheckout'       => 'InitiateCheckout',
            'Purchase'               => 'Purchase',
        ];

        return $map[$name] ?? ($map[strtolower($name)] ?? $name);
    }
}
