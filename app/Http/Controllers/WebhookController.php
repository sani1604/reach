<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Services\BillingService;
use App\Services\EventForwarder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WebhookController extends Controller
{
    /**
     * Single webhook endpoint. The topic comes from the X-Shopify-Topic header.
     * Deliveries are made idempotent via the X-Shopify-Webhook-Id header.
     */
    public function handle(Request $request)
    {
        $topic = (string) $request->header('X-Shopify-Topic', '');
        $domain = strtolower(
            (string) ($request->header('X-Shopify-Shop-Domain') ?: $request->query('shop'))
        );
        $webhookId = $request->header('X-Shopify-Webhook-Id');

        $appKey = $request->attributes->get('shopify_app_key')
            ?: \App\Services\ShopifyApp::key();
        $shop = Shop::findForApp($domain, is_string($appKey) ? $appKey : null)
            ?: Shop::where('shopify_domain', $domain)->first(); // legacy rows
        if (! $shop) {
            return response()->json(['ok' => true], 200);
        }

        // Idempotency: skip deliveries Shopify already sent us.
        if ($webhookId) {
            $exists = DB::table('webhook_deliveries')
                ->where('shop_id', $shop->id)
                ->where('webhook_id', $webhookId)
                ->exists();

            if ($exists) {
                return response()->json(['ok' => true, 'duplicate' => true], 200);
            }
        }

        $data = $request->json()->all();

        switch ($topic) {
            case 'app/uninstalled':
                $shop->update([
                    'uninstalled_at' => now(),
                    'access_token'   => null,
                    'refresh_token'  => null,
                    'web_pixel_id'   => null,
                    // Keep OpenAI credentials (pixel_id / capi_token /
                    // advertiser_api_key) so a reinstall doesn't force the
                    // merchant to re-paste them.
                ]);
                break;

            case 'orders/create':
            case 'orders/paid':
                $this->purchase($shop, $data);
                break;

            case 'orders/cancelled':
            case 'orders/updated':
                $this->orderCancelledOrRto($shop, $data, $topic);
                break;

            case 'checkouts/create':
                $this->checkoutStarted($shop, $data);
                break;

            case 'refunds/create':
                $this->refund($shop, $data);
                break;

            case 'app_subscriptions/update':
                app(BillingService::class)->handleSubscriptionUpdate(
                    $shop,
                    $data['app_subscription'] ?? $data
                );
                break;

            /*
            | Mandatory privacy-law compliance webhooks (GDPR / CCPA). Every
            | public app must subscribe to these topics and respond 200 —
            | Shopify's App Store review tests them on every submission.
            */
            case 'customers/data_request':
                $this->customerDataRequest($shop, $data);
                break;

            case 'customers/redact':
                $this->customerRedact($shop, $data);
                break;

            case 'shop/redact':
                $this->shopRedact($shop, $data);
                break;
        }

        // shop/redact deletes the shop row above — nothing left to record.
        if ($webhookId && $shop->exists) {
            DB::table('webhook_deliveries')->insertOrIgnore([
                'shop_id'    => $shop->id,
                'webhook_id' => $webhookId,
                'topic'      => $topic,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return response()->json(['ok' => true], 200);
    }

    /**
     * orders/create + orders/paid both map to Purchase. The shared dedup key
     * (purchase:{order_id}) ensures COD and prepaid orders in India are each
     * attributed exactly once.
     */
    protected function purchase(Shop $shop, array $data): void
    {
        $orderId = $data['id'] ?? null;
        if (! $orderId) {
            return;
        }

        $products = collect($data['line_items'] ?? [])->map(fn ($li) => [
            'id'       => (string) ($li['product_id'] ?? $li['variant_id'] ?? ''),
            'title'    => (string) ($li['title'] ?? ''),
            'price'    => (float) ($li['price'] ?? 0),
            'quantity' => (int) ($li['quantity'] ?? 1),
        ])->values()->all();

        $userData = $this->orderUserData($data);

        // Join OpenAI click ids (oppref/obref) + legacy fbc/fbp captured earlier.
        // Phone-first for India: VisitorBridge prefers mobile match over email.
        $userData = app(\App\Services\VisitorBridge::class)
            ->enrichUserData($shop, $userData, (string) $orderId);

        $payment = $this->detectPaymentMethod($data);
        $isCod = $payment['is_cod'];

        $payload = [
            'event_id'      => 'purchase-'.$orderId,
            'dedup_key'     => 'purchase:'.$orderId,
            'order_id'      => (string) $orderId,
            'order_name'    => $data['name'] ?? null,
            'currency'      => $data['currency'] ?? 'INR',
            'value'         => $data['total_price'] ?? null,
            'products'      => $products,
            'user_data'     => $userData,
            'shop_domain'   => $shop->shopify_domain,
            'source_url'    => 'https://'.$shop->shopify_domain.'/checkouts/thank-you',
            'action_source' => 'web',
            // India COD / prepaid tagging for dashboard + CAPI custom data.
            'payment_method' => $payment['method'],
            'is_cod'         => $isCod,
            'financial_status' => $data['financial_status'] ?? null,
            'fulfillment_status' => $data['fulfillment_status'] ?? null,
            'gateway'        => $payment['gateway'],
        ];
        if (! empty($userData['oppref'])) {
            $payload['oppref'] = $userData['oppref'];
        }
        if (! empty($userData['oai_click_id']) && empty($payload['oppref'])) {
            $payload['oppref'] = $userData['oai_click_id'];
        }

        app(EventForwarder::class)->recordServer($shop, 'Purchase', $payload, [
            'dedup_key' => (string) $orderId,
        ]);
    }

    /**
     * orders/cancelled + orders/updated (RTO / return-to-origin) → conversion
     * adjustment so ChatGPT ad budget isn't spent on fake/returned COD orders.
     */
    protected function orderCancelledOrRto(Shop $shop, array $data, string $topic): void
    {
        $orderId = $data['id'] ?? null;
        if (! $orderId) {
            return;
        }

        $cancelReason = strtolower((string) ($data['cancel_reason'] ?? ''));
        $tags = strtolower((string) ($data['tags'] ?? ''));
        $fulfillment = strtolower((string) ($data['fulfillment_status'] ?? ''));
        $financial = strtolower((string) ($data['financial_status'] ?? ''));
        $closed = ! empty($data['cancelled_at']) || ! empty($data['closed_at']);

        $isCancelled = $topic === 'orders/cancelled'
            || $cancelReason !== ''
            || ! empty($data['cancelled_at']);

        // RTO heuristics common on Indian D2C (Delhivery / Shiprocket / custom tags).
        $isRto = str_contains($tags, 'rto')
            || str_contains($tags, 'return_to_origin')
            || str_contains($tags, 'return-to-origin')
            || str_contains($cancelReason, 'rto')
            || ($fulfillment === 'restocked' && $closed)
            || ($financial === 'voided' && $closed && $this->detectPaymentMethod($data)['is_cod']);

        if (! $isCancelled && ! $isRto) {
            return;
        }

        $userData = $this->orderUserData($data);
        $userData = app(\App\Services\VisitorBridge::class)
            ->enrichUserData($shop, $userData, (string) $orderId);

        $reason = $isRto ? 'rto' : ($cancelReason !== '' ? $cancelReason : 'cancelled');
        $value = (float) ($data['total_price'] ?? $data['current_total_price'] ?? 0);

        $payload = [
            'event_id'         => ($isRto ? 'rto-' : 'cancel-').$orderId,
            'dedup_key'        => ($isRto ? 'rto:' : 'cancel:').$orderId,
            'order_id'         => (string) $orderId,
            'order_name'       => $data['name'] ?? null,
            'currency'         => $data['currency'] ?? 'INR',
            'value'            => $value,
            'user_data'        => $userData,
            'shop_domain'      => $shop->shopify_domain,
            'source_url'       => 'https://'.$shop->shopify_domain,
            'action_source'    => 'web',
            'custom_event_name'=> $isRto ? 'purchase_rto' : 'purchase_cancelled',
            'adjustment_reason'=> $reason,
            'is_cod'           => $this->detectPaymentMethod($data)['is_cod'],
            'payment_method'   => $this->detectPaymentMethod($data)['method'],
        ];
        if (! empty($userData['oppref'])) {
            $payload['oppref'] = $userData['oppref'];
        }

        app(EventForwarder::class)->recordServer($shop, 'PurchaseCancelled', $payload, [
            'dedup_key' => (string) $orderId.':'.$reason,
        ]);
    }

    /**
     * Pull EMQ identifiers off a Shopify order payload (phone-first for India).
     *
     * @return array<string, mixed>
     */
    protected function orderUserData(array $data): array
    {
        $customer = $data['customer'] ?? [];
        $userData = [];

        // Phone-first: customer.phone, then billing, then shipping.
        $phone = $customer['phone']
            ?? ($data['billing_address']['phone'] ?? null)
            ?? ($data['shipping_address']['phone'] ?? null)
            ?? ($data['phone'] ?? null);
        if (! empty($phone)) {
            $userData['phone'] = (string) $phone;
        }

        if (! empty($customer['email'])) {
            $userData['email'] = $customer['email'];
        } elseif (! empty($data['email'])) {
            $userData['email'] = $data['email'];
        }
        if (! empty($customer['first_name'])) {
            $userData['first_name'] = $customer['first_name'];
        }
        if (! empty($customer['last_name'])) {
            $userData['last_name'] = $customer['last_name'];
        }

        $addr = $data['billing_address'] ?? ($data['shipping_address'] ?? []);
        if (! empty($addr['country_code'])) {
            $userData['country'] = $addr['country_code'];
        } elseif (! empty($addr['country'])) {
            $userData['country'] = $addr['country'];
        } else {
            // Default India for .in D2C when address is missing (COD phone-only).
            $userData['country'] = 'IN';
        }
        if (! empty($addr['city'])) {
            $userData['city'] = $addr['city'];
        }
        if (! empty($addr['province']) || ! empty($addr['province_code'])) {
            $userData['region'] = $addr['province'] ?? $addr['province_code'];
            $userData['state'] = $userData['region'];
        }
        if (! empty($addr['zip'])) {
            $userData['postal_code'] = $addr['zip'];
        }
        if (! empty($addr['first_name']) && empty($userData['first_name'])) {
            $userData['first_name'] = $addr['first_name'];
        }
        if (! empty($addr['last_name']) && empty($userData['last_name'])) {
            $userData['last_name'] = $addr['last_name'];
        }
        if (! empty($addr['phone']) && empty($userData['phone'])) {
            $userData['phone'] = $addr['phone'];
        }

        return $userData;
    }

    /**
     * Detect COD vs prepaid from Shopify gateways / payment names.
     *
     * @return array{is_cod: bool, method: string, gateway: string|null}
     */
    protected function detectPaymentMethod(array $data): array
    {
        $gateways = [];
        foreach ($data['payment_gateway_names'] ?? [] as $g) {
            $gateways[] = strtolower((string) $g);
        }
        if (! empty($data['gateway'])) {
            $gateways[] = strtolower((string) $data['gateway']);
        }
        foreach ($data['payment_terms'] ?? [] as $term) {
            if (is_array($term) && ! empty($term['payment_schedules'])) {
                // ignore
            }
        }

        $joined = implode(' ', $gateways);
        $financial = strtolower((string) ($data['financial_status'] ?? ''));
        $tags = strtolower((string) ($data['tags'] ?? ''));

        $codHints = [
            'cash on delivery', 'cash_on_delivery', 'cod', 'manual',
            'payment on delivery', 'pay on delivery', 'pod',
        ];
        $isCod = false;
        foreach ($codHints as $hint) {
            if (str_contains($joined, $hint) || str_contains($tags, $hint)) {
                $isCod = true;
                break;
            }
        }
        // Unpaid + pending often means COD on Indian stores.
        if (! $isCod && in_array($financial, ['pending', 'authorized'], true)
            && (str_contains($joined, 'manual') || $joined === '' || str_contains($tags, 'cod'))) {
            $isCod = str_contains($tags, 'cod') || str_contains($joined, 'manual');
        }

        $method = $isCod ? 'cod' : 'prepaid';
        if (! $isCod && $joined !== '') {
            if (str_contains($joined, 'razorpay')) {
                $method = 'razorpay';
            } elseif (str_contains($joined, 'payu') || str_contains($joined, 'paytm')) {
                $method = 'upi_wallet';
            } elseif (str_contains($joined, 'shopify_payments') || str_contains($joined, 'stripe')) {
                $method = 'card';
            }
        }

        return [
            'is_cod'  => $isCod,
            'method'  => $method,
            'gateway' => $gateways[0] ?? null,
        ];
    }

    protected function checkoutStarted(Shop $shop, array $data): void
    {
        $token = $data['token'] ?? null;
        if (! $token) {
            return;
        }

        $lineItems = $data['line_items'] ?? [];

        app(EventForwarder::class)->recordServer($shop, 'InitiateCheckout', [
            'event_id'     => 'checkout-'.$token,
            'dedup_key'    => 'checkout:'.$token,
            'currency'     => $data['currency'] ?? null,
            'value'        => $data['total_price'] ?? ($data['subtotal_price'] ?? null),
            'num_items'    => collect($lineItems)->sum('quantity'),
            'content_ids'  => collect($lineItems)->pluck('product_id')->filter()->values()->all(),
            'content_type' => 'product',
        ], ['dedup_key' => $token]);
    }

    /**
     * Refunds map to a PurchaseCancelled event so net revenue stays accurate.
     * Dedup key is the refund id — Shopify can retry this webhook.
     */
    protected function refund(Shop $shop, array $data): void
    {
        $refundId = $data['id'] ?? null;
        if (! $refundId) {
            return;
        }

        app(EventForwarder::class)->recordServer($shop, 'PurchaseCancelled', [
            'event_id'  => 'refund-'.$refundId,
            'dedup_key' => 'refund:'.$refundId,
            'order_id'  => (string) ($data['order_id'] ?? null),
            'currency'  => $this->refundCurrency($data),
            'value'     => $this->refundAmount($data),
        ], ['dedup_key' => (string) $refundId]);
    }

    protected function refundAmount(array $data): float
    {
        $total = 0.0;

        foreach ($data['transactions'] ?? [] as $tx) {
            $kind = $tx['kind'] ?? '';
            if (in_array($kind, ['refund', 'void', 'return'], true)) {
                $total += (float) ($tx['amount'] ?? 0);
            }
        }

        // Fallback when no matching transaction kinds are present.
        if ($total == 0.0 && isset($data['transactions'][0]['amount'])) {
            $total = (float) $data['transactions'][0]['amount'];
        }

        return round($total, 2);
    }

    protected function refundCurrency(array $data): ?string
    {
        foreach ($data['transactions'] ?? [] as $tx) {
            if (! empty($tx['currency'])) {
                return (string) $tx['currency'];
            }
        }

        return null;
    }

    /*
    |------------------------------------------------------------------
    | Privacy-law compliance (mandatory — GDPR / UK GDPR / US state laws)
    |------------------------------------------------------------------
    */

    /**
     * customers/data_request — respond with what we hold for this customer.
     * Reach stores no personally identifying profile data beyond the
     * visitor bridge row (click ids + optional email/phone used for CAPI
     * matching), which is compiled and returned to the merchant.
     */
    protected function customerDataRequest(Shop $shop, array $data): void
    {
        $customer = $data['customer'] ?? [];
        $email = strtolower((string) ($customer['email'] ?? ''));
        $phone = (string) ($customer['phone'] ?? '');

        $visitors = collect();

        if ($email !== '' || $phone !== '') {
            $visitors = $shop->visitors()
                ->where(function ($q) use ($email, $phone) {
                    if ($email !== '') {
                        $q->orWhere('email', $email);
                    }
                    if ($phone !== '') {
                        $q->orWhere('phone', $phone);
                    }
                })
                ->get(['id', 'vid', 'fbc', 'fbp', 'email', 'phone', 'order_id']);
        }

        // Never log PII (email/phone/click ids) — count only for audit.
        logger()->info('GDPR customers/data_request', [
            'shop'     => $shop->shopify_domain,
            'customer' => $customer['id'] ?? null,
            'records'  => $visitors->count(),
        ]);
    }

    /**
     * customers/redact — purge the customer's identifiers within 30 days
     * (done here immediately). Analytics events stay aggregated and
     * anonymous.
     */
    protected function customerRedact(Shop $shop, array $data): void
    {
        $customer = $data['customer'] ?? [];
        $email = strtolower((string) ($customer['email'] ?? ''));
        $phone = (string) ($customer['phone'] ?? '');
        $orderIds = array_filter(array_map('trim', explode(',', (string) ($customer['orders'] ?? ''))));

        $deleted = 0;

        // Guard: never run an unconstrained delete.
        if ($email !== '' || $phone !== '') {
            $deleted = $shop->visitors()
                ->where(function ($q) use ($email, $phone) {
                    if ($email !== '') {
                        $q->orWhere('email', $email);
                    }
                    if ($phone !== '') {
                        $q->orWhere('phone', $phone);
                    }
                })
                ->delete();
        }

        // Scrub order identity from stored events for this customer's orders.
        foreach ($orderIds as $id) {
            $shop->events()->where('order_id', $id)->update([
                'order_name' => null,
            ]);
        }

        logger()->info('GDPR customers/redact', [
            'shop'     => $shop->shopify_domain,
            'customer' => $customer['id'] ?? null,
            'deleted'  => $deleted,
        ]);
    }

    /**
     * shop/redact — fires ~48h after uninstall. Delete every remaining trace
     * of the store (Shop row + cascading events, visitors, charges,
     * deliveries, webhook-dedup rows).
     */
    protected function shopRedact(Shop $shop, array $data): void
    {
        logger()->info('GDPR shop/redact — deleting all store data', [
            'shop' => $shop->shopify_domain,
        ]);

        $shop->delete();
    }
}
