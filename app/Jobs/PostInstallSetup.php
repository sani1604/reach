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
 * Runs after a store is installed (token exchange or OAuth grant):
 *
 *  1. Registers commerce webhooks at runtime (fallback — with Shopify
 *     managed app setup the subscriptions in shopify.app.toml are deployed
 *     via `shopify app deploy`, this only matters for manually configured
 *     Partner Dashboard apps).
 *  2. Activates the deployed web pixel extension for this store via the
 *     `webPixelCreate` GraphQL mutation. Deploying an extension does NOT
 *     activate it per store — without this step the Reach Pixel never shows
 *     up in Customer Events and never tracks anything.
 */
class PostInstallSetup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** Retry a few minutes apart — extension deploys propagate lazily. */
    public int $backoff = 120;

    public function __construct(public int $shopId)
    {
    }

    public function handle(ShopifyClient $client): void
    {
        $shop = Shop::find($this->shopId);

        if (! $shop || ! $shop->isInstalled()) {
            return;
        }

        try {
            $client->subscribeWebhooks($shop);
        } catch (Throwable $e) {
            logger()->warning('Webhook subscription failed', [
                'shop'  => $shop->shopify_domain,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $client->ensureWebPixel($shop);
        } catch (Throwable $e) {
            logger()->warning('Web pixel activation failed (will retry)', [
                'shop'  => $shop->shopify_domain,
                'error' => $e->getMessage(),
            ]);

            // Extension may not be propagated from the latest deploy yet.
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff);
            }
        }
    }
}
