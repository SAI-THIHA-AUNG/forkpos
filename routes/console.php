<?php

use App\Console\Commands\PushInvoicesCommand;
use App\Console\Commands\SyncMastersCommand;
use App\Console\Commands\SyncPosProfilesCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled sync — keep the local catalog + invoices in step with ERPNext
|--------------------------------------------------------------------------
*/
// Pull master data (items, prices, customers, stock) from ERPNext.
Schedule::command(SyncMastersCommand::class)
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

// Push pending POS invoices to ERPNext.
Schedule::command(PushInvoicesCommand::class)
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Sync POS profiles from ERPNext.
Schedule::command(SyncPosProfilesCommand::class)
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->runInBackground();
