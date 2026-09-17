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
 *     `webPixelCreate` Admin API mutation. Deploying an extension does NOT
 *     activate it per store — without this step the Reach Pixel never shows
 *     up in Customer Events and Shopify admin shows "Pixels: Disconnected".
 *
 * Also callable synchronously via runNow() so the first boot / Settings
 * reconnect doesn't depend on a queue worker being online.
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

        $this->runFor($shop, $client, throwOnPixelFailure: $this->job !== null);
    }

    /**
     * Synchronous path used by the Settings "Reconnect pixel" button and by
     * the install boot when we want activation without waiting on a worker.
     *
     * @return array{ok: bool, web_pixel_id?: string|null, error?: string}
     */
    public static function runNow(Shop $shop, ?ShopifyClient $client = null): array
    {
        $client = $client ?: app(ShopifyClient::class);

        return (new self($shop->id))->runFor($shop, $client, throwOnPixelFailure: false);
    }

    /**
     * @return array{ok: bool, web_pixel_id?: string|null, error?: string}
     */
    protected function runFor(Shop $shop, ShopifyClient $client, bool $throwOnPixelFailure): array
    {
        try {
            $client->subscribeWebhooks($shop);
        } catch (Throwable $e) {
            logger()->warning('Webhook subscription failed', [
                'shop'  => $shop->shopify_domain,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $outcome = $client->ensureWebPixelDetailed($shop);

            if (! ($outcome['ok'] ?? false)) {
                logger()->warning('Web pixel activation failed', [
                    'shop'   => $shop->shopify_domain,
                    'error'  => $outcome['error'] ?? null,
                    'errors' => $outcome['errors'] ?? null,
                ]);

                if ($throwOnPixelFailure && $this->attempts() < $this->tries) {
                    $this->release($this->backoff);
                }

                return [
                    'ok'           => false,
                    'web_pixel_id' => null,
                    'error'        => $outcome['error'] ?? 'web_pixel_activation_failed',
                ];
            }

            return [
                'ok'           => true,
                'web_pixel_id' => $outcome['id'] ?? $shop->fresh()->web_pixel_id,
            ];
        } catch (Throwable $e) {
            logger()->warning('Web pixel activation threw (will retry)', [
                'shop'  => $shop->shopify_domain,
                'error' => $e->getMessage(),
            ]);

            if ($throwOnPixelFailure && $this->attempts() < $this->tries) {
                $this->release($this->backoff);
            }

            return [
                'ok'           => false,
                'web_pixel_id' => null,
                'error'        => $e->getMessage(),
            ];
        }
    }
}
