<?php

namespace App\Jobs;

use App\Models\Shop;
use App\Services\ProductFeedBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Builds the OpenAI Ads product feed off the HTTP request so nginx never
 * 504s on large catalogs. One unique job per shop at a time.
 */
class SyncProductFeed implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    /** Allow slow Shopify pagination on big catalogs. */
    public int $timeout = 240;

    public array $backoff = [15, 60];

    public int $uniqueFor = 300;

    public function __construct(public int $shopId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'feed-sync:'.$this->shopId;
    }

    public function handle(ProductFeedBuilder $builder): void
    {
        $shop = Shop::find($this->shopId);
        if (! $shop || ! $shop->isInstalled()) {
            return;
        }

        @set_time_limit(240);

        $shop->forceFill([
            'feed_status' => 'syncing',
        ])->save();

        try {
            $built = $builder->build($shop, 1500);
            $builder->persist($shop, $built);
        } catch (Throwable $e) {
            logger()->warning('SyncProductFeed failed', [
                'shop'  => $shop->shopify_domain,
                'error' => $e->getMessage(),
            ]);

            $meta = is_array($shop->feed_meta) ? $shop->feed_meta : [];
            $meta['last_error'] = $e->getMessage();
            $meta['last_error_at'] = now()->toIso8601String();

            $shop->forceFill([
                'feed_status' => 'error',
                'feed_meta'   => $meta,
            ])->save();

            throw $e;
        }
    }

    public static function cachedBody(int $shopId, string $format): ?string
    {
        $path = "feeds/{$shopId}.{$format}";
        if (! Storage::disk('local')->exists($path)) {
            return null;
        }

        return Storage::disk('local')->get($path);
    }

    public function failed(?Throwable $e): void
    {
        $shop = Shop::find($this->shopId);
        if (! $shop) {
            return;
        }

        $meta = is_array($shop->feed_meta) ? $shop->feed_meta : [];
        $meta['last_error'] = $e?->getMessage() ?: 'Feed sync failed';
        $meta['last_error_at'] = now()->toIso8601String();

        $shop->forceFill([
            'feed_status' => 'error',
            'feed_meta'   => $meta,
        ])->save();
    }
}
