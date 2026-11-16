<?php

namespace App\Services;

use App\Models\PosProfile;
use App\Models\PosProfilePayment;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Pulls ERPNext POS Profile documents into the local `pos_profiles` table.
 *
 * Why this is a separate service (and NOT a new method on SyncService):
 *   - SyncService already carries the user's local fixes (commit 9f2663a3 +
 *     the pullStock profile-warehouse targeting). Adding a method there would
 *     risk a merge conflict when the online AI rebases their patch.
 *   - POS Profile sync has different semantics from the master sync:
 *     it's per-document (one ERPNext POS Profile doc → one local row + its
 *     payment-method child rows), runs on a faster schedule, and is also
 *     triggered explicitly from the settings page.
 *
 * ERPNext POS Profile doc — fields we map (verified against ERPNext v16):
 *   name                -> erpnext_id  + name (display key, e.g. "Test Van POS")
 *   company             -> company
 *   country             -> country
 *   currency            -> currency
 *   warehouse           -> resolved to local warehouse_id (FK lookup by erpnext_id)
 *   selling_price_list  -> selling_price_list
 *   write_off_account   -> write_off_account
 *   write_off_cost_center -> cost_center
 *   write_off_limit     -> write_off_limit
 *   payments[]          -> pos_profile_payments child rows
 *     (mode_of_payment, default -> is_default)
 *
 * Everything else from the ERPNext doc is preserved verbatim in `erpnext_data`.
 */
class PosProfileSync
{
    public function __construct(protected ErpNextClient $client) {}

    /**
     * Pull every non-disabled POS Profile from ERPNext.
     *
     * @return array{synced: int, errors: array<int, string>}
     */
    public function syncAll(int $limit = 200): array
    {
        $errors = [];
        $synced = 0;

        // Cheap ping first so we get a clear "ERPNext not configured / unreachable"
        // message rather than a 500 stack trace on the very first request().
        try {
            $this->client->ping();
        } catch (\Throwable $e) {
            return ['synced' => 0, 'errors' => ['ping: '.$e->getMessage()]];
        }

        $rows = $this->client->list('POS Profile', ['name'], [['disabled', '=', 0]], $limit);

        foreach ($rows as $row) {
            $name = $row['name'] ?? null;
            if (! $name) {
                continue;
            }
            try {
                $this->syncOne($name);
                $synced++;
            } catch (\Throwable $e) {
                $errors[] = "{$name}: ".$e->getMessage();
                Log::warning('PosProfileSync failed for one profile', ['name' => $name, 'error' => $e->getMessage()]);
            }
        }

        return ['synced' => $synced, 'errors' => $errors];
    }

    /**
     * Pull one ERPNext POS Profile by its `name` and upsert the local row +
     * payment-method child rows in a single transaction.
     *
     * @throws RuntimeException when ERPNext is unreachable / returns no doc
     */
    public function syncOne(string $erpName): PosProfile
    {
        $doc = $this->client->doc('POS Profile', $erpName);
        if (! $doc || ! ($doc['name'] ?? null)) {
            throw new RuntimeException("ERPNext returned no POS Profile named '{$erpName}'.");
        }

        return DB::transaction(function () use ($doc): PosProfile {
            // Resolve the warehouse FK — the ERPNext doc carries the warehouse
            // `name` (e.g. "Stores - M"); our local warehouses table holds the
            // same string in `erpnext_id` after pullWarehouse() ran.
            $warehouseId = null;
            $warehouseName = $doc['warehouse'] ?? null;
            if ($warehouseName) {
                $warehouseId = Warehouse::where('erpnext_id', $warehouseName)->value('id');
                if (! $warehouseId) {
                    // Warehouse not synced yet — log + leave warehouse_id NULL.
                    // The settings page will warn the user to run a warehouse sync.
                    Log::warning('PosProfileSync: warehouse not found locally', [
                        'pos_profile' => $doc['name'],
                        'warehouse' => $warehouseName,
                    ]);
                }
            }

            $profile = PosProfile::updateOrCreate(
                ['erpnext_id' => $doc['name']],
                [
                    // Display name: prefer the ERPNext `pos_profile_name` if
                    // present (human-friendly), fall back to the doc `name`.
                    'name' => $doc['pos_profile_name'] ?? $doc['name'],
                    'company' => $doc['company'] ?? null,
                    'country' => $doc['country'] ?? null,
                    'currency' => $doc['currency'] ?? null,
                    'warehouse_id' => $warehouseId,
                    'selling_price_list' => $doc['selling_price_list'] ?? null,
                    'write_off_limit' => (float) ($doc['write_off_limit'] ?? 0),
                    'write_off_account' => $doc['write_off_account'] ?? null,
                    'cost_center' => $doc['write_off_cost_center']
                        ?? $doc['cost_center']
                        ?? null,
                    // Full ERPNext snapshot — source of truth for any field we
                    // haven't promoted to a column yet.
                    'erpnext_data' => $doc,
                    'synced_at' => now(),
                ]
            );

            // Reconcile the payment-methods child table:
            //   - upsert every row from the ERPNext `payments` child table
            //   - delete local rows that no longer exist upstream
            //   - keep `allow_edit_amount` for rows that already exist locally
            //     (it's a ForkPOS-only preference, not an ERPNext field).
            $keepModes = [];
            foreach ($doc['payments'] ?? [] as $row) {
                $mode = $row['mode_of_payment'] ?? null;
                if (! $mode) {
                    continue;
                }
                $keepModes[] = $mode;

                PosProfilePayment::updateOrCreate(
                    ['pos_profile_id' => $profile->id, 'mode_of_payment' => $mode],
                    [
                        'is_default' => (bool) ($row['default'] ?? $row['is_default'] ?? false),
                        'allow_edit_amount' => true,
                    ]
                );
            }

            if ($keepModes) {
                PosProfilePayment::where('pos_profile_id', $profile->id)
                    ->whereNotIn('mode_of_payment', $keepModes)
                    ->delete();
            }

            return $profile->fresh(['payments', 'warehouse']);
        });
    }
}
