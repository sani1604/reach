<?php

namespace App\Http\Controllers;

use App\Jobs\SyncProductFeed;
use App\Models\Shop;
use App\Services\ProductFeedBuilder;
use App\Services\ShopDomain;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Throwable;

class ProductFeedController extends Controller
{
    public function index(Request $request)
    {
        $shop = $request->attributes->get('shop');
        $this->ensureFeedToken($shop);

        // Never build the catalog on the page request — that caused nginx 504s.
        // Queue a background job on first visit / ?refresh=1.
        if ($request->boolean('refresh') || (! $shop->feed_synced_at && $shop->feed_status !== 'syncing')) {
            $this->queueSync($shop);
            $shop = $shop->fresh();
        }

        $meta = is_array($shop->feed_meta) ? $shop->feed_meta : [];
        $issues = $meta['issues'] ?? [];
        $sample = $meta['sample'] ?? [];
        $stats = $meta['stats'] ?? [
            'total'         => (int) $shop->feed_item_count,
            'ready'         => max(0, (int) $shop->feed_item_count - (int) $shop->feed_issue_count),
            'issues'        => (int) $shop->feed_issue_count,
            'out_of_stock'  => 0,
            'missing_image' => 0,
            'missing_price' => 0,
        ];

        $feedUrl = route('feed.public', [
            'shop'  => $shop->shopify_domain,
            'token' => $shop->feed_token,
        ]);

        $error = null;
        if ($shop->feed_status === 'error' && ! empty($meta['last_error'])) {
            $error = (string) $meta['last_error'];
        }

        return view('feed', [
            'shop'      => $shop,
            'stats'     => $stats,
            'issues'    => array_slice($issues, 0, 40),
            'sample'    => $sample,
            'feedUrl'   => $feedUrl,
            'error'     => $error,
            'syncing'   => $shop->feed_status === 'syncing',
            'statusUrl' => route('feed.status'),
        ]);
    }

    /**
     * Lightweight JSON poller so the UI refreshes when the job finishes.
     */
    public function status(Request $request)
    {
        $shop = $request->attributes->get('shop');
        $meta = is_array($shop->feed_meta) ? $shop->feed_meta : [];

        return response()->json([
            'status'       => $shop->feed_status,
            'syncing'      => $shop->feed_status === 'syncing',
            'item_count'   => (int) $shop->feed_item_count,
            'issue_count'  => (int) $shop->feed_issue_count,
            'synced_at'    => $shop->feed_synced_at?->toIso8601String(),
            'synced_human' => $shop->feed_synced_at?->diffForHumans(),
            'error'        => $meta['last_error'] ?? null,
            'stats'        => $meta['stats'] ?? null,
        ]);
    }

    public function syncNow(Request $request)
    {
        $shop = $request->attributes->get('shop');
        $this->ensureFeedToken($shop);

        if ($shop->feed_status === 'syncing') {
            return back()->with('feed_ok', 'Catalog sync already running — this page will update when it finishes.');
        }

        $this->queueSync($shop);

        return back()->with('saved', true)->with(
            'feed_ok',
            'Catalog sync started. Large stores take up to a minute — this page refreshes automatically.'
        );
    }

    /**
     * Public download — token-gated. Serves the cached file when available.
     */
    public function download(Request $request, string $shop, string $token, ProductFeedBuilder $builder)
    {
        $domain = ShopDomain::normalize($shop);
        if (! $domain || ! is_string($token) || strlen($token) < 20 || strlen($token) > 128) {
            abort(404);
        }

        // Rate-limit public feed pulls (token is a capability URL).
        $rlKey = 'feed-dl:'.$request->ip().':'.$domain;
        if (RateLimiter::tooManyAttempts($rlKey, 30)) {
            abort(429, 'Too many feed requests');
        }
        RateLimiter::hit($rlKey, 60);

        $row = Shop::findForApp($domain) ?: Shop::where('shopify_domain', $domain)->first();

        // Constant-time token compare to reduce timing leaks on the secret.
        if (
            ! $row
            || ! $row->isInstalled()
            || ! is_string($row->feed_token)
            || ! hash_equals($row->feed_token, $token)
        ) {
            abort(404);
        }

        $format = strtolower((string) $request->query('format', 'tsv'));
        if (! in_array($format, ['tsv', 'csv'], true)) {
            $format = 'tsv';
        }

        $body = SyncProductFeed::cachedBody($row->id, $format);

        if ($body === null) {
            if ($row->feed_status === 'syncing') {
                return response('Feed is still building. Retry in a moment.', 503, [
                    'Retry-After'  => '30',
                    'Content-Type' => 'text/plain; charset=UTF-8',
                ]);
            }

            try {
                @set_time_limit(180);
                $built = $builder->build($row, 1500);
                $builder->persist($row, $built);
                $body = $format === 'csv'
                    ? $builder->toCsv($built['items'])
                    : $builder->toTsv($built['items']);
            } catch (Throwable $e) {
                logger()->warning('Public feed download failed', [
                    'shop'  => $domain,
                    'error' => $e->getMessage(),
                ]);
                abort(503, 'Feed temporarily unavailable');
            }
        }

        $filename = 'openai-ads-feed-'.Str::slug(str_replace('.myshopify.com', '', $domain)).'.'.$format;

        return response($body, 200, [
            'Content-Type'        => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'text/tab-separated-values; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control'       => 'public, max-age=300',
        ]);
    }

    protected function queueSync(Shop $shop): void
    {
        $shop->forceFill([
            'feed_status' => 'syncing',
        ])->save();

        Cache::forget('feed-sync-done:'.$shop->id);

        $shopId = (int) $shop->id;

        // 1) Persist a queue job so a supervisor worker can pick it up / retry.
        SyncProductFeed::dispatch($shopId)->onQueue('default');

        // 2) Also run after the HTTP response is flushed. Nginx already got a
        //    fast 302, so merchants never see a 504 — and hosts without a live
        //    queue worker still finish the catalog build in this PHP process.
        app()->terminating(function () use ($shopId) {
            // Skip if a worker already claimed the unique job and finished.
            $shop = Shop::find($shopId);
            if (! $shop || $shop->feed_status !== 'syncing') {
                return;
            }

            $lock = Cache::lock('feed-sync-run:'.$shopId, 240);
            if (! $lock->get()) {
                return;
            }

            try {
                @set_time_limit(240);
                @ignore_user_abort(true);
                (new SyncProductFeed($shopId))->handle(app(ProductFeedBuilder::class));
            } catch (Throwable $e) {
                logger()->warning('terminating feed sync failed', [
                    'shop_id' => $shopId,
                    'error'   => $e->getMessage(),
                ]);
            } finally {
                try {
                    $lock->release();
                } catch (Throwable) {
                    // lock may have expired
                }
            }
        });
    }

    protected function ensureFeedToken(Shop $shop): void
    {
        if ($shop->feed_token) {
            return;
        }

        $shop->forceFill([
            'feed_token' => Str::random(40),
        ])->save();
    }
}
