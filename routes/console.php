<?php

use App\Console\Commands\PushInvoicesCommand;
use App\Console\Commands\SyncCatalogCommand;
use App\Console\Commands\SyncPosProfilesCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled work — one cron line (`* * * * * php artisan schedule:run`) is enough
|--------------------------------------------------------------------------
*/
// Drain the queue (profile catalog syncs) without needing a separate worker process.
Schedule::command('queue:work --stop-when-empty --max-time=55')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Delta-sync catalogs of profiles that have an open shift.
Schedule::command(SyncCatalogCommand::class)
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Push pending POS invoices to ERPNext.
Schedule::command(PushInvoicesCommand::class)
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Re-pull the POS profiles this terminal serves (flags, payments, applicable users).
Schedule::command(SyncPosProfilesCommand::class)
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground();
