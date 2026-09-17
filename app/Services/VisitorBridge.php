<?php

namespace App\Services;

use App\Jobs\SendCapiEvent;
use App\Models\Event;
use App\Models\Shop;
use App\Models\Visitor;

/**
 * Joins browser-side click IDs (oppref/obref + legacy fbc/fbp) to server-side
 * Purchase events.
 *
 * Two paths:
 *  1. Deterministic — the pixel on the order-status/thank-you page calls
 *     /api/enrich with the order id + click ids, which are attached to the
 *     already-recorded Purchase and re-forwarded to the Conversions API.
 *  2. Best-effort — the order webhook looks the visitor up by email/phone
 *     (or by order_id, when enrichment arrived first) and merges click ids
 *     into the Purchase before forwarding.
 */
class VisitorBridge
{
    /**
     * Create or update the visitor's identity profile.
     */
    public function upsert(Shop $shop, array $input): ?Visitor
    {
        $vid = $input['vid'] ?? null;
        if (! $vid) {
            return null;
        }

        $userData = $input['user_data'] ?? [];

        $fbc     = $userData['fbc'] ?? ($input['fbc'] ?? null);
        $fbp     = $userData['fbp'] ?? ($input['fbp'] ?? null);
        $oppref  = $userData['oppref'] ?? ($input['oppref'] ?? null);
        $obref   = $userData['obref'] ?? ($input['obref'] ?? null);
        $email   = $input['email'] ?? ($userData['email'] ?? null);
        $phone   = $input['phone'] ?? ($userData['phone'] ?? null);
        $orderId = $input['order_id'] ?? null;

        $visitor = Visitor::firstOrNew([
            'shop_id' => $shop->id,
            'vid'     => (string) $vid,
        ]);

        $visitor->fbc    = $fbc ?: $visitor->fbc;
        $visitor->fbp    = $fbp ?: $visitor->fbp;
        $visitor->oppref = $oppref ?: $visitor->oppref;
        $visitor->obref  = $obref ?: $visitor->obref;
        $visitor->email  = $email ?: $visitor->email;
        $visitor->phone  = $phone ?: $visitor->phone;

        if ($orderId) {
            $visitor->order_id = (string) $orderId;
        }

        $visitor->last_seen_at = now();
        $visitor->save();

        return $visitor;
    }

    /**
     * Best-effort join at order-webhook time: find a visitor by email, phone
     * or order_id and merge their click ids into the Purchase's user data.
     */
    public function enrichUserData(Shop $shop, array $userData, ?string $orderId = null): array
    {
        // Already fully matched — nothing to do.
        if (! empty($userData['oppref']) && ! empty($userData['obref'])) {
            return $userData;
        }

        $visitor = null;
        if (! empty($userData['email'])) {
            $visitor = Visitor::where('shop_id', $shop->id)
                ->where('email', $userData['email'])
                ->latest('last_seen_at')->first();
        } elseif (! empty($userData['phone'])) {
            $visitor = Visitor::where('shop_id', $shop->id)
                ->where('phone', $userData['phone'])
                ->latest('last_seen_at')->first();
        } elseif ($orderId) {
            $visitor = Visitor::where('shop_id', $shop->id)
                ->where('order_id', (string) $orderId)
                ->latest('last_seen_at')->first();
        }

        if (! $visitor) {
            return $userData;
        }

        $userData['fbc']    = $userData['fbc'] ?? $visitor->fbc;
        $userData['fbp']    = $userData['fbp'] ?? $visitor->fbp;
        $userData['oppref'] = $userData['oppref'] ?? $visitor->oppref;
        $userData['obref']  = $userData['obref'] ?? $visitor->obref;

        if (! empty($userData['oppref']) || ! empty($userData['obref'])
            || ! empty($userData['fbc']) || ! empty($userData['fbp'])) {
            if (empty($userData['vid']) && $visitor->vid) {
                $userData['vid'] = $visitor->vid;
            }
            if (empty($userData['email']) && $visitor->email) {
                $userData['email'] = $visitor->email;
            }
            if (empty($userData['phone']) && $visitor->phone) {
                $userData['phone'] = $visitor->phone;
            }
        }

        return $userData;
    }

    /**
     * Deterministic enrichment: attach click ids to a recorded Purchase and
     * re-forward it to the Conversions API (same event_id, so OpenAI dedups).
     */
    public function enrichPurchase(Shop $shop, array $data): bool
    {
        $orderId   = $data['order_id'] ?? null;
        $orderName = $data['order_name'] ?? null;

        $query = Event::where('shop_id', $shop->id)->where('event_name', 'Purchase');
        if ($orderId) {
            $query->where('order_id', (string) $orderId);
        } elseif ($orderName) {
            $query->where('order_name', (string) $orderName);
        } else {
            return false;
        }

        $purchase = $query->first();
        if (! $purchase) {
            return false; // order webhook hasn't landed yet — visitor keeps order_id for later join
        }

        $payload = $purchase->payload ?? [];
        $userData = $payload['user_data'] ?? [];

        $changed = false;
        foreach (['fbc', 'fbp', 'oppref', 'obref', 'vid', 'email', 'phone'] as $key) {
            if (! empty($data[$key]) && empty($userData[$key])) {
                $userData[$key] = $data[$key];
                $changed = true;
            }
        }

        // Also accept nested user_data from the pixel.
        foreach (['fbc', 'fbp', 'oppref', 'obref'] as $key) {
            if (! empty($data['user_data'][$key]) && empty($userData[$key])) {
                $userData[$key] = $data['user_data'][$key];
                $changed = true;
            }
        }

        if (! $changed) {
            return false;
        }

        $payload['user_data'] = $userData;
        if (! empty($userData['oppref']) && empty($payload['oppref'])) {
            $payload['oppref'] = $userData['oppref'];
        }
        $purchase->payload = $payload;
        $purchase->save();

        if ($shop->capiReady()) {
            $event = app(EventMapper::class)->build('Purchase', array_merge($payload, [
                'event_id'    => $purchase->event_id,
                'event_time'  => $purchase->occurred_at?->timestamp ?? time(),
                'shop_domain' => $shop->shopify_domain,
                'source_url'  => $payload['source_url'] ?? ('https://'.$shop->shopify_domain),
            ]));
            SendCapiEvent::dispatch($shop->id, $event)->onQueue('capi');
        }

        return true;
    }
}
