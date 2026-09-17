<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Services\ProductFeedBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class ProductFeedController extends Controller
{
    public function index(Request $request, ProductFeedBuilder $builder)
    {
        $shop = $request->attributes->get('shop');
        $this->ensureFeedToken($shop);

        $preview = null;
        $error = null;

        // Lazy build on first visit / refresh so Settings-only shops still work.
        if ($request->boolean('refresh') || ! $shop->feed_synced_at) {
            try {
                $preview = $this->sync($shop, $builder);
                $shop = $shop->fresh();
            } catch (Throwable $e) {
                $error = $e->getMessage();
                logger()->warning('Product feed sync failed', [
                    'shop'  => $shop->shopify_domain,
                    'error' => $error,
                ]);
            }
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

        return view('feed', [
            'shop'    => $shop,
            'stats'   => $stats,
            'issues'  => array_slice($issues, 0, 40),
            'sample'  => $sample,
            'feedUrl' => $feedUrl,
            'error'   => $error,
            'preview' => $preview,
        ]);
    }

    public function syncNow(Request $request, ProductFeedBuilder $builder)
    {
        $shop = $request->attributes->get('shop');
        $this->ensureFeedToken($shop);

        try {
            $this->sync($shop, $builder);
        } catch (Throwable $e) {
            return back()->with('error', 'Feed sync failed: '.$e->getMessage());
        }

        return back()->with('saved', true)->with('feed_ok', 'Catalog synced for OpenAI Ads.');
    }

    /**
     * Public download URL — no session. Authenticated by per-shop feed_token.
     */
    public function download(Request $request, string $shop, string $token, ProductFeedBuilder $builder)
    {
        $domain = strtolower($shop);
        $row = Shop::where('shopify_domain', $domain)->where('feed_token', $token)->first();

        if (! $row || ! $row->isInstalled()) {
            abort(404);
        }

        $format = strtolower((string) $request->query('format', 'tsv'));
        if (! in_array($format, ['tsv', 'csv'], true)) {
            $format = 'tsv';
        }

        try {
            $built = $builder->build($row);
        } catch (Throwable $e) {
            abort(503, 'Feed temporarily unavailable');
        }

        // Keep meta fresh on download too.
        $this->persist($row, $built);

        $body = $format === 'csv'
            ? $builder->toCsv($built['items'])
            : $builder->toTsv($built['items']);

        $filename = 'openai-ads-feed-'.Str::slug(str_replace('.myshopify.com', '', $domain)).'.'.$format;

        return response($body, 200, [
            'Content-Type'        => $format === 'csv' ? 'text/csv; charset=UTF-8' : 'text/tab-separated-values; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control'       => 'public, max-age=300',
        ]);
    }

    protected function sync(Shop $shop, ProductFeedBuilder $builder): array
    {
        $built = $builder->build($shop);
        $this->persist($shop, $built);

        return $built;
    }

    protected function persist(Shop $shop, array $built): void
    {
        $stats = $built['stats'];
        $issues = $built['issues'];
        $sample = array_slice($built['items'], 0, 8);

        $status = 'ready';
        if ($stats['total'] === 0) {
            $status = 'empty';
        } elseif ($stats['issues'] > 0 && $stats['ready'] === 0) {
            $status = 'issues';
        } elseif ($stats['issues'] > 0) {
            $status = 'issues';
        }

        $shop->update([
            'feed_item_count'  => $stats['total'],
            'feed_issue_count' => $stats['issues'],
            'feed_synced_at'   => now(),
            'feed_status'      => $status,
            'feed_meta'        => [
                'stats'  => $stats,
                'issues' => array_slice($issues, 0, 100),
                'sample' => $sample,
            ],
        ]);
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
