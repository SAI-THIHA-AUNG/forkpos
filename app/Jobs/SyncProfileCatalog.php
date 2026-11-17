<?php

namespace App\Jobs;

use App\Models\PosProfile;
use App\Services\ProfileCatalogSync;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Queued, per-profile catalog pull. Unique per profile so double-clicks / overlaps don't stack. */
class SyncProfileCatalog implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public int $uniqueFor = 900;

    public function __construct(public int $profileId, public bool $full = false) {}

    public function uniqueId(): string
    {
        return (string) $this->profileId;
    }

    public function handle(ProfileCatalogSync $sync): void
    {
        $profile = PosProfile::find($this->profileId);
        if ($profile && $profile->isErpnextSynced()) {
            $sync->run($profile, $this->full);
        }
    }
}
