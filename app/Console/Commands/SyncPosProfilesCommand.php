<?php

namespace App\Console\Commands;

use App\Services\PosProfileSync;
use Illuminate\Console\Command;
use Throwable;

/**
 * Pull POS Profile documents from ERPNext into the local `pos_profiles` table.
 *
 * Usage:
 *   php artisan forkpos:sync-profiles                  # sync all non-disabled profiles
 *   php artisan forkpos:sync-profiles "Test Van POS"   # sync one by ERPNext name
 *
 * Scheduled via routes/console.php to run every 15 minutes.
 */
class SyncPosProfilesCommand extends Command
{
    protected $signature = 'forkpos:sync-profiles
                            {name? : ERPNext POS Profile name to sync (omit to sync all)}
                            {--limit=200 : Max profiles per pull when using --all}
                            {--all : Pull EVERY ERPNext profile, not just the ones enabled on this terminal}';

    protected $description = 'Pull POS Profile documents from ERPNext (currency, warehouse, payment methods, ...)';

    public function handle(PosProfileSync $sync): int
    {
        $name = $this->argument('name');

        if ($name) {
            $this->info("Syncing POS Profile '{$name}' from ERPNext...");

            try {
                $profile = $sync->syncOne($name);
                $this->info(sprintf(
                    'OK: %s - currency=%s, warehouse=%s, payments=%d',
                    $profile->name,
                    $profile->currency ?: '(none)',
                    $profile->warehouse?->name ?? '(none)',
                    $profile->payments()->count(),
                ));

                return self::SUCCESS;
            } catch (Throwable $e) {
                $this->error($e->getMessage());

                return self::FAILURE;
            }
        }

        $this->info($this->option('all')
            ? 'Pulling all non-disabled POS Profiles from ERPNext...'
            : 'Re-pulling the POS Profiles enabled on this terminal...');
        $result = $this->option('all')
            ? $sync->syncAll((int) $this->option('limit'))
            : $sync->syncEnabled();

        $this->info(sprintf('Synced %d profile(s).', $result['synced']));

        foreach ($result['errors'] as $error) {
            $this->warn("FAILED: {$error}");
        }

        return $result['errors'] ? self::FAILURE : self::SUCCESS;
    }
}
