<?php

namespace App\Console\Commands;

use App\Models\Shop;
use App\Services\ShopifyClient;
use Illuminate\Console\Command;

/**
 * Refresh expiring offline access tokens (2026 Shopify policy).
 *
 * Public apps must hold a valid expiring offline token for every installed
 * store: access tokens live ~1 hour, refresh tokens ~90 days (rotated on
 * every use). Background work (webhooks, CAPI forwarding, health checks)
 * refreshes lazily via ShopifyClient::ensureFreshToken(); this command is
 * the safety net for quiet stores, run daily from cron.
 */
class RefreshTokens extends Command
{
    protected $signature = 'reach:refresh-tokens {--force : Refresh even when the stored token is not close to expiry}';

    protected $description = 'Refresh expiring Shopify offline access tokens before they expire';

    public function handle(ShopifyClient $client): int
    {
        $force = (bool) $this->option('force');

        $shops = Shop::query()
            ->whereNotNull('access_token')
            ->whereNull('uninstalled_at')
            ->when(! $force, function ($query) {
                $query->where(function ($q) {
                    // Tokens expired or expiring within 12 hours, or refresh
                    // tokens approaching their 90-day deadline.
                    $q->where('token_expires_at', '<=', now()->addHours(12))
                        ->orWhere('refresh_token_expires_at', '<=', now()->addDays(7));
                });
            })
            ->get();

        if ($shops->isEmpty()) {
            $this->info('No tokens need refreshing.');

            return self::SUCCESS;
        }

        $refreshed = 0;
        $failed = 0;

        foreach ($shops as $shop) {
            if ($client->refreshOfflineToken($shop)) {
                $refreshed++;
                $this->line("  ✓ {$shop->shopify_domain}");
            } else {
                $failed++;
                $this->line("  ✗ {$shop->shopify_domain} — refresh failed (merchant may need to reopen the app)");
            }
        }

        $this->info("Refreshed {$refreshed} token(s), {$failed} failed.");

        return self::SUCCESS;
    }
}
