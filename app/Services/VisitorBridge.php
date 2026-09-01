<?php

namespace App\Services;

use App\Jobs\SendCapiEvent;
use App\Models\Event;
use App\Models\Shop;
use App\Models\Visitor;

/**
 * Joins browser-side click IDs (fbc/fbp) to server-side Purchase events.
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
        $email   = $input['email'] ?? ($userData['email'] ?? null);
        $phone   = $input['phone'] ?? ($userData['phone'] ?? null);
        $orderId = $input['order_id'] ?? null;

        $visitor = Visitor::firstOrNew([
            'shop_id' => $shop->id,
            'vid'     => (string) $vid,
        ]);

        $visitor->fbc   = $fbc ?: $visitor->fbc;
        $visitor->fbp   = $fbp ?: $visitor->fbp;
        $visitor->email = $email ?: $visitor->email;
        $visitor->phone = $phone ?: $visitor->phone;

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
        if (isset($userData['fbc']) && isset($userData['fbp'])) {
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

        $userData['fbc'] = $userData['fbc'] ?? $visitor->fbc;
        $userData['fbp'] = $userData['fbp'] ?? $visitor->fbp;

        if (! empty($userData['fbc']) || ! empty($userData['fbp'])) {
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
        $fbc       = $data['fbc'] ?? null;
        $fbp       = $data['fbp'] ?? null;
        $vid       = $data['vid'] ?? null;
        $email     = $data['email'] ?? null;
        $phone     = $data['phone'] ?? null;

        if (! $fbc && ! $fbp && ! $vid && ! $email && ! $phone) {
            return false;
        }

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
        foreach (['fbc', 'fbp', 'vid', 'email', 'phone'] as $key) {
            if (! empty($data[$key]) && empty($userData[$key])) {
                $userData[$key] = $data[$key];
                $changed = true;
            }
        }

        if (! $changed) {
            return false;
        }

        $payload['user_data'] = $userData;
        $purchase->payload = $payload;
        $purchase->save();

        if ($shop->pixelConfigured()) {
            $event = app(EventMapper::class)->build('Purchase', array_merge($payload, [
                'event_time' => $purchase->occurred_at?->timestamp ?? time(),
            ]));
            SendCapiEvent::dispatch($shop->id, $event)->onQueue('capi');
        }

        return true;
    }
}
