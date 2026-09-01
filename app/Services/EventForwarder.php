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
     * Log a browser-side event (the storefront pixel already fired it to
     * OpenAI directly; here we record it for the dashboard).
     */
    public function recordBrowser(Shop $shop, string $eventName, array $data): ?Event
    {
        $eventId = (string) ($data['event_id'] ?? Str::uuid());
        $dedupKey = $data['dedup_key']
            ?? "browser:{$eventName}:{$eventId}";

        return $this->deduper->register($shop, $eventName, $eventId, $dedupKey, 'browser', [
            'currency'    => $data['currency'] ?? null,
            'value'       => $data['value'] ?? null,
            'occurred_at' => isset($data['event_time'])
                ? \Carbon\Carbon::createFromTimestamp((int) $data['event_time'])
                : now(),
            'payload'     => $data,
        ]);
    }

    /**
     * Log a server-side event and enqueue the CAPI forward.
     */
    public function recordServer(Shop $shop, string $eventName, array $data, array $meta = []): ?Event
    {
        $eventId = (string) ($data['event_id'] ?? Str::uuid());
        $dedupKey = $data['dedup_key']
            ?? "server:{$eventName}:".($meta['dedup_key'] ?? $eventId);

        $event = $this->deduper->register($shop, $eventName, $eventId, $dedupKey, 'server', [
            'order_id'    => $data['order_id'] ?? null,
            'order_name'  => $data['order_name'] ?? null,
            'currency'    => $data['currency'] ?? null,
            'value'       => $data['value'] ?? null,
            'occurred_at' => now(),
            'payload'     => $data,
        ]);

        if ($event && $shop->pixelConfigured()) {
            SendCapiEvent::dispatch($shop->id, $this->mapper->build($eventName, $data))
                ->onQueue('capi');
        }

        return $event;
    }
}
