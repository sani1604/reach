<?php

namespace App\Jobs;

use App\Models\Shop;
use App\Services\ShopifyClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Runtime fallback that (re)subscribes commerce webhooks for a shop.
 *
 * Kept as its own job class so older queue payloads (serialized before the
 * PostInstallSetup merge) still deserialize and run instead of crashing the
 * worker with "Class App\Jobs\SubscribeWebhooks not found" → Lost connection.
 *
 * Prefer PostInstallSetup for new installs — it does webhooks + web pixel.
 */
class SubscribeWebhooks implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [30, 60, 120];

    /**
     * Don't retry forever on permanent failures (missing shop, bad token…).
     */
    public int $maxExceptions = 2;

    public function __construct(public int $shopId)
    {
    }

    public function handle(ShopifyClient $client): void
    {
        $shop = Shop::find($this->shopId);

        if (! $shop || ! $shop->isInstalled()) {
            // Drop silently — nothing left to subscribe for.
            return;
        }

        try {
            $client->subscribeWebhooks($shop);
        } catch (Throwable $e) {
            logger()->warning('SubscribeWebhooks failed', [
                'shop'  => $shop->shopify_domain,
                'error' => $e->getMessage(),
            ]);

            // Transient network / 5xx → retry. Auth / permanent → give up.
            $msg = strtolower($e->getMessage());
            $permanent = str_contains($msg, '401')
                || str_contains($msg, '403')
                || str_contains($msg, 'invalid')
                || str_contains($msg, 'unavailable shop');

            if (! $permanent) {
                throw $e;
            }
        }
    }

    /**
     * If the job payload is corrupt / shop gone, don't kill the worker.
     */
    public function failed(?Throwable $e): void
    {
        logger()->warning('SubscribeWebhooks permanently failed', [
            'shop_id' => $this->shopId,
            'error'   => $e?->getMessage(),
        ]);
    }
}
