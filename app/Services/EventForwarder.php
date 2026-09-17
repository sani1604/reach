<?php

namespace App\Services;

use App\Jobs\SendCapiEvent;
use App\Models\Event;
use App\Models\Shop;
use Illuminate\Support\Str;

class EventForwarder
{
    public function __construct(
        private EventDeduper $deduper,
        private EventMapper $mapper,
    ) {
    }

    /**
     * Log a browser-side event AND forward it server-side to the OpenAI
     * Conversions API. The browser Measurement Pixel (oaiq) may also fire the
     * same event_id for dual delivery + OpenAI-side dedup.
     *
     * Previously this only wrote to the local dashboard — events never reached
     * OpenAI from the storefront path, which is why "events are not firing".
     */
    public function recordBrowser(Shop $shop, string $eventName, array $data): ?Event
    {
        $eventId = (string) ($data['event_id'] ?? Str::uuid());
        $dedupKey = $data['dedup_key']
            ?? "browser:{$eventName}:{$eventId}";

        // Attach shop domain so the mapper can fill source_url when missing.
        if (empty($data['shop_domain'])) {
            $data['shop_domain'] = $shop->shopify_domain;
        }
        if (empty($data['source_url']) && empty($data['url'])) {
            $data['source_url'] = 'https://'.$shop->shopify_domain;
        }

        $event = $this->deduper->register($shop, $eventName, $eventId, $dedupKey, 'browser', [
            'currency'    => $data['currency'] ?? null,
            'value'       => $data['value'] ?? null,
            'occurred_at' => isset($data['event_time'])
                ? \Carbon\Carbon::createFromTimestamp((int) $data['event_time'])
                : now(),
            'payload'     => $data,
        ]);

        // Forward every browser event server-side so ad-blockers / Safari ITP
        // cannot drop the signal. OpenAI dedups on (pixel_id, type, id).
        if ($event && $shop->capiReady()) {
            $payload = $data;
            $payload['event_id'] = $eventId;
            SendCapiEvent::dispatch($shop->id, $this->mapper->build($eventName, $payload))
                ->onQueue('capi');
        }

        return $event;
    }

    /**
     * Log a server-side event and enqueue the CAPI forward.
     */
    public function recordServer(Shop $shop, string $eventName, array $data, array $meta = []): ?Event
    {
        $eventId = (string) ($data['event_id'] ?? Str::uuid());
        $dedupKey = $data['dedup_key']
            ?? "server:{$eventName}:".($meta['dedup_key'] ?? $eventId);

        if (empty($data['shop_domain'])) {
            $data['shop_domain'] = $shop->shopify_domain;
        }
        if (empty($data['source_url']) && empty($data['url'])) {
            $data['source_url'] = 'https://'.$shop->shopify_domain;
        }

        $event = $this->deduper->register($shop, $eventName, $eventId, $dedupKey, 'server', [
            'order_id'    => $data['order_id'] ?? null,
            'order_name'  => $data['order_name'] ?? null,
            'currency'    => $data['currency'] ?? null,
            'value'       => $data['value'] ?? null,
            'occurred_at' => now(),
            'payload'     => $data,
        ]);

        if ($event && $shop->capiReady()) {
            $payload = $data;
            $payload['event_id'] = $eventId;
            SendCapiEvent::dispatch($shop->id, $this->mapper->build($eventName, $payload))
                ->onQueue('capi');
        }

        return $event;
    }
}
