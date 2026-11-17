<?php

namespace App\Console\Commands;

use App\Jobs\SyncProfileCatalog;
use App\Models\PosOpeningShift;
use App\Models\PosProfile;
use Illuminate\Console\Command;

/**
 * Delta-sync the catalog of every ERPNext profile that currently has an open
 * shift (or one named profile). Replaces the old blind "pull everything every
 * 5 minutes" schedule.
 */
class SyncCatalogCommand extends Command
{
    protected $signature = 'forkpos:sync-catalog
                            {profile? : Local POS profile id (default: every profile with an open shift)}
                            {--full : Ignore delta cursors and re-pull everything}
                            {--now : Run inline instead of queueing}';

    protected $description = 'Pull items, prices, stock and customers for POS profiles with an open shift';

    public function handle(): int
    {
        $ids = $this->argument('profile')
            ? [(int) $this->argument('profile')]
            : PosOpeningShift::where('status', PosOpeningShift::STATUS_OPEN)->pluck('pos_profile_id')->unique()->all();

        $profiles = PosProfile::whereIn('id', $ids)->whereNotNull('erpnext_id')->where('device_enabled', true)->get();
        if ($profiles->isEmpty()) {
            $this->info('No ERPNext profile to sync.');

            return self::SUCCESS;
        }

        foreach ($profiles as $profile) {
            $job = new SyncProfileCatalog($profile->id, (bool) $this->option('full'));
            if ($this->option('now')) {
                dispatch_sync($job);
                $this->info("Synced: {$profile->name}");
            } else {
                dispatch($job);
                $this->info("Queued: {$profile->name}");
            }
        }

        return self::SUCCESS;
    }
}
