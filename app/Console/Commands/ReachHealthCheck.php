<?php

namespace App\Console\Commands;

use App\Models\Shop;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class ReachHealthCheck extends Command
{
    protected $signature = 'reach:health';

    protected $description = 'Detect stores whose pixel stopped sending events and alert (Basic plan feature)';

    public function handle(): int
    {
        $shops = Shop::whereNull('uninstalled_at')->whereNotNull('access_token')->get();
        $flagged = 0;

        foreach ($shops as $shop) {
            $latest = $shop->events()->max('occurred_at');

            if (! $latest || $latest->lt(now()->subHours(48))) {
                $flagged++;
                $this->warn("Stale pixel: {$shop->shopify_domain} (last event ".($latest ? $latest->diffForHumans() : 'never').')');

                // Only Basic-plan stores get the email alert; mail is a no-op
                // unless MAIL_* is configured in .env.
                if ($shop->isOnPaidPlan()) {
                    Mail::raw(
                        "Heads up — Reach hasn't received events from {$shop->shopify_domain} for 48 hours. Check your pixel settings.",
                        fn ($mail) => $mail->to(config('mail.from.address'))->subject('[Reach] Pixel may be broken')
                    );
                }
            }
        }

        $this->info("Health check complete. {$flagged} store(s) flagged.");

        return 0;
    }
}
