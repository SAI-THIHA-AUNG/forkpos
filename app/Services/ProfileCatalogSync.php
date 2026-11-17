<?php

namespace App\Services;

use App\Models\PosProfile;
use App\Models\SyncState;
use App\Models\Tax;
use App\Models\Warehouse;

/**
 * Pulls ONE POS profile's catalog from ERPNext, step by step, recording
 * progress in `sync_states` so the UI can show a progress screen and later
 * runs can fetch only what changed (`modified` delta cursor).
 *
 * Order matters: warehouse → tax → item → item_price → stock → customer.
 * Triggered when a shift opens, by the scheduler for profiles with an open
 * shift, and by the "Retry" button on the preparing screen.
 */
class ProfileCatalogSync
{
    /** Entities the register cannot work without. */
    public const REQUIRED = ['item', 'item_price', 'stock'];

    public const STEPS = ['warehouse', 'tax', 'item', 'item_price', 'stock', 'customer'];

    public function __construct(protected SyncService $sync) {}

    /**
     * @param  bool  $full  ignore cursors and re-pull everything
     * @return array<string, string> entity => final status
     */
    public function run(PosProfile $profile, bool $full = false): array
    {
        $ctx = SyncContext::forProfile($profile);
        $outcome = [];

        foreach (self::STEPS as $entity) {
            $state = SyncState::firstOrCreate(
                ['pos_profile_id' => $profile->id, 'entity' => $entity],
                ['status' => SyncState::PENDING]
            );
            $cursor = $full ? null : $state->cursor;

            $state->update(['status' => SyncState::RUNNING, 'started_at' => now(), 'finished_at' => null, 'error' => null]);

            $result = $this->sync->pullMasters([$entity], 500, $ctx->withSince($cursor ? [$entity => $cursor] : []));

            if ($result['errors']) {
                $state->update([
                    'status' => SyncState::FAILED,
                    'error' => mb_substr(implode(' | ', $result['errors']), 0, 1000),
                    'finished_at' => now(),
                ]);
                $outcome[$entity] = SyncState::FAILED;

                continue;
            }

            $stats = $result['results'][$entity] ?? [];
            $state->update([
                'status' => SyncState::DONE,
                'rows' => (int) ($stats['upserted'] ?? 0),
                'cursor' => $stats['max_modified'] ?? $cursor,
                'finished_at' => now(),
            ]);
            $outcome[$entity] = SyncState::DONE;

            $this->afterStep($profile, $ctx, $entity);
        }

        return $outcome;
    }

    /** Link what a step just pulled back onto the profile. */
    protected function afterStep(PosProfile $profile, SyncContext $ctx, string $entity): void
    {
        if ($entity === 'warehouse' && ! $profile->warehouse_id && $ctx->warehouse) {
            $id = Warehouse::where('erpnext_id', $ctx->warehouse)->value('id');
            if ($id) {
                $profile->update(['warehouse_id' => $id]);
            }
        }

        if ($entity === 'tax' && $ctx->taxTemplate) {
            $inclusive = Tax::where('erpnext_id', $ctx->taxTemplate)->value('included_in_price');
            if ($inclusive !== null) {
                $profile->update(['tax_inclusive' => (bool) $inclusive]);
            }
        }
    }

    /**
     * Can the register open? Local-only profiles (no ERPNext link) are always
     * ready; synced profiles need their required entities pulled at least once.
     */
    public function isReady(PosProfile $profile): bool
    {
        if (! $profile->isErpnextSynced()) {
            return true;
        }

        $done = SyncState::where('pos_profile_id', $profile->id)
            ->where('status', SyncState::DONE)
            ->pluck('entity')
            ->all();

        return count(array_diff(self::REQUIRED, $done)) === 0;
    }

    /** Progress payload for the preparing screen. */
    public function progress(PosProfile $profile): array
    {
        $states = SyncState::where('pos_profile_id', $profile->id)->get()->keyBy('entity');
        $steps = [];
        foreach (self::STEPS as $entity) {
            $s = $states->get($entity);
            $steps[] = [
                'entity' => $entity,
                'status' => $s?->status ?? SyncState::PENDING,
                'rows' => $s?->rows ?? 0,
                'error' => $s?->error,
            ];
        }

        return [
            'ready' => $this->isReady($profile),
            'failed' => collect($steps)->contains(fn ($s) => $s['status'] === SyncState::FAILED),
            'steps' => $steps,
        ];
    }
}
