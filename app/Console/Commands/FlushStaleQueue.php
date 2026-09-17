<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Clears failed / un-deserializable queue jobs that kill `queue:work`
 * (e.g. leftover App\Jobs\SubscribeWebhooks payloads from older deploys).
 */
class FlushStaleQueue extends Command
{
    protected $signature = 'reach:flush-queue
        {--failed-only : Only clear the failed_jobs table}
        {--all : Clear pending jobs + failed jobs (destructive)}';

    protected $description = 'Clear failed/stale queue jobs that crash the worker';

    public function handle(): int
    {
        $failed = 0;
        $pending = 0;

        if (Schema::hasTable('failed_jobs')) {
            $failed = DB::table('failed_jobs')->count();
            DB::table('failed_jobs')->delete();
            $this->info("Cleared {$failed} failed job(s).");
        }

        if ($this->option('all') && Schema::hasTable('jobs')) {
            $pending = DB::table('jobs')->count();
            DB::table('jobs')->delete();
            $this->warn("Cleared {$pending} pending job(s).");
        } elseif (! $this->option('failed-only') && Schema::hasTable('jobs')) {
            // Drop only payloads that reference missing job classes.
            $rows = DB::table('jobs')->get(['id', 'payload']);
            $removed = 0;
            foreach ($rows as $row) {
                $payload = json_decode($row->payload, true) ?: [];
                $command = $payload['data']['commandName']
                    ?? ($payload['displayName'] ?? null);

                if ($command && ! class_exists($command)) {
                    DB::table('jobs')->where('id', $row->id)->delete();
                    $removed++;
                    $this->line("  removed stale job #{$row->id} ({$command})");
                }
            }
            $this->info("Removed {$removed} stale pending job(s) with missing classes.");
        }

        $this->info('Done. Restart: php artisan queue:work --queue=capi,default --tries=3 --sleep=1');

        return self::SUCCESS;
    }
}
