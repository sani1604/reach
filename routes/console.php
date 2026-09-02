<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled tasks (cron: `php artisan schedule:run` every minute)
|--------------------------------------------------------------------------
| Shared-hosting friendly: one cron entry, everything else is queued.
*/

// Keep Shopify expiring offline access tokens current (2026 policy).
Schedule::command('reach:refresh-tokens')->dailyAt('03:17');

// Flag stores whose pixel stopped sending events.
Schedule::command('reach:health')->dailyAt('09:02');

Artisan::command('inspire', function () {
    $this->comment(\Illuminate\Foundation\Inspiring::quote());
})->purpose('Display an inspiring quote');
