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

        $shop = Shop::where('shopify_domain', $domain)->first();
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
                $shop->update(['uninstalled_at' => now(), 'access_token' => null]);
                break;

            case 'orders/create':
            case 'orders/paid':
                $this->purchase($shop, $data);
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
        }

        if ($webhookId) {
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

        $customer = $data['customer'] ?? [];
        $userData = [];
        if (! empty($customer['email'])) {
            $userData['email'] = $customer['email'];
        }
        if (! empty($customer['phone'])) {
            $userData['phone'] = $customer['phone'];
        }

        // Join click IDs (fbc/fbp) captured earlier by the browser pixel.
        $userData = app(\App\Services\VisitorBridge::class)
            ->enrichUserData($shop, $userData, (string) $orderId);

        app(EventForwarder::class)->recordServer($shop, 'Purchase', [
            'event_id'   => 'purchase-'.$orderId,
            'dedup_key'  => 'purchase:'.$orderId,
            'order_id'   => (string) $orderId,
            'order_name' => $data['name'] ?? null,
            'currency'   => $data['currency'] ?? null,
            'value'      => $data['total_price'] ?? null,
            'products'   => $products,
            'user_data'  => $userData,
        ], ['dedup_key' => (string) $orderId]);
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
}
