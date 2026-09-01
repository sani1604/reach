<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Shop;
use Illuminate\Database\QueryException;

/**
 * Inserts events with a unique dedup_key so browser + server duplicates
 * (and delivery retries) are stored exactly once.
 */
class EventDeduper
{
    public function register(
        Shop $shop,
        string $eventName,
        string $eventId,
        string $dedupKey,
        string $source,
        array $fields = []
    ): ?Event {
        $this->resetUsageIfNeeded($shop);

        try {
            $event = $shop->events()->create(array_merge($fields, [
                'event_name' => $eventName,
                'event_id'   => $eventId,
                'dedup_key'  => $dedupKey,
                'source'     => $source,
            ]));

            $shop->increment('monthly_event_count');

            return $event;
        } catch (QueryException $e) {
            if ($this->isDuplicate($e)) {
                return null; // already tracked — silently deduped
            }

            throw $e;
        }
    }

    /**
     * Detect a unique-constraint violation across MySQL, SQLite and Postgres.
     */
    protected function isDuplicate(QueryException $e): bool
    {
        $sqlState = $e->errorInfo[0] ?? null;

        return $sqlState === '23000'
            || str_contains($e->getMessage(), 'Duplicate entry');
    }

    protected function resetUsageIfNeeded(Shop $shop): void
    {
        $start = now()->startOfMonth();
        if (! $shop->events_reset_at || $shop->events_reset_at->lt($start)) {
            $shop->update([
                'monthly_event_count' => 0,
                'events_reset_at'     => $start,
            ]);
        }
    }
}
