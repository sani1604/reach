<?php

namespace App\Services;

use App\Models\Event;
use Illuminate\Support\Collection;

/**
 * Detects whether an event/order is attributed to OpenAI / ChatGPT Ads.
 *
 * Signals (any one is enough):
 *  - oppref / oai_click_id / chatgpt_aid / oai_cid (OpenAI click ids)
 *  - utm_source or utm_medium containing "chatgpt" or "openai"
 *
 * Used so dashboard "Net revenue from OpenAI Ads" is ads-attributed only,
 * not whole-store Shopify revenue.
 */
class OpenAiAttribution
{
    /**
     * True when the event payload carries an OpenAI / ChatGPT Ads signal.
     */
    public static function isAttributed(?array $payload): bool
    {
        if ($payload === null || $payload === []) {
            return false;
        }

        $userData = is_array($payload['user_data'] ?? null) ? $payload['user_data'] : [];

        foreach ([
            $payload['oppref'] ?? null,
            $payload['oai_click_id'] ?? null,
            $payload['chatgpt_aid'] ?? null,
            $payload['oai_cid'] ?? null,
            $userData['oppref'] ?? null,
            $userData['oai_click_id'] ?? null,
            $userData['chatgpt_aid'] ?? null,
            $userData['oai_cid'] ?? null,
        ] as $clickId) {
            if (is_string($clickId) && $clickId !== '') {
                return true;
            }
        }

        $source = strtolower((string) (
            $payload['utm_source']
            ?? $userData['utm_source']
            ?? ''
        ));
        $medium = strtolower((string) (
            $payload['utm_medium']
            ?? $userData['utm_medium']
            ?? ''
        ));

        foreach ([$source, $medium] as $value) {
            if ($value !== '' && (
                str_contains($value, 'chatgpt')
                || str_contains($value, 'openai')
            )) {
                return true;
            }
        }

        return false;
    }

    /**
     * Filter a collection of Event models down to OpenAI-attributed ones.
     *
     * @param  Collection<int, Event>  $events
     * @return Collection<int, Event>
     */
    public static function filterAttributed(Collection $events): Collection
    {
        return $events->filter(
            fn (Event $event) => self::isAttributed($event->payload ?? [])
        )->values();
    }

    /**
     * Sum event values for attributed events only.
     *
     * @param  Collection<int, Event>  $events
     */
    public static function sumValue(Collection $events): float
    {
        return (float) self::filterAttributed($events)->sum(
            fn (Event $event) => (float) ($event->value ?? 0)
        );
    }

    /**
     * Count distinct order_id among attributed purchase events.
     *
     * @param  Collection<int, Event>  $events
     */
    public static function distinctOrders(Collection $events): int
    {
        return self::filterAttributed($events)
            ->pluck('order_id')
            ->filter()
            ->unique()
            ->count();
    }

    /**
     * Refunds that belong to an attributed purchase (same order_id), or that
     * themselves carry an OpenAI signal.
     *
     * @param  Collection<int, Event>  $purchases
     * @param  Collection<int, Event>  $refunds
     * @return Collection<int, Event>
     */
    public static function attributedRefunds(Collection $purchases, Collection $refunds): Collection
    {
        $attributedOrderIds = self::filterAttributed($purchases)
            ->pluck('order_id')
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->all();

        return $refunds->filter(function (Event $event) use ($attributedOrderIds) {
            if (self::isAttributed($event->payload ?? [])) {
                return true;
            }

            $orderId = $event->order_id !== null ? (string) $event->order_id : null;

            return $orderId !== null && in_array($orderId, $attributedOrderIds, true);
        })->values();
    }
}
