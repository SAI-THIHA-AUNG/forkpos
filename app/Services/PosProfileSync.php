<?php

namespace App\Services;

use App\Models\PosProfile;
use App\Models\PosProfilePayment;
use App\Models\Setting;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\PosProfileConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

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
        } catch (Throwable $e) {
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
            } catch (Throwable $e) {
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

        $profile = DB::transaction(function () use ($doc): PosProfile {
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

            // Field mapping lives in PosProfileConfig — the only place that
            // knows ERPNext field names and local defaults.
            $attributes = array_merge(PosProfileConfig::synced($doc), ['warehouse_id' => $warehouseId]);
            $profile = PosProfile::where('erpnext_id', $doc['name'])->first();
            if ($profile) {
                $profile->update($attributes);
            } else {
                $profile = PosProfile::create(
                    array_merge(PosProfileConfig::localDefaults(), $attributes, ['erpnext_id' => $doc['name']])
                );
            }

            $this->importUsers($profile);

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

        $this->mirrorStoreDefaults($doc);

        return $profile;
    }

    /**
     * ERPNext owns the store identity: mirror the profile's company, currency
     * and selling price list onto the singleton settings row so receipts,
     * invoice currency and the settings page no longer need manual entry.
     *
     * The currency symbol/precision are pulled from the ERPNext Currency doc
     * once per currency (it is a store-wide value, not a per-profile field).
     *
     * @param  array<string, mixed>  $doc
     */
    protected function mirrorStoreDefaults(array $doc): void
    {
        $setting = Setting::current();
        $changes = [];

        $company = trim((string) ($doc['company'] ?? ''));
        if ($company !== '') {
            $changes['company_name'] = $company;
            $changes['erpnext_company'] = $company;
        }

        if (! empty($doc['selling_price_list'])) {
            $changes['erpnext_price_list'] = (string) $doc['selling_price_list'];
        }

        $currency = strtoupper(trim((string) ($doc['currency'] ?? '')));
        if ($currency !== '') {
            $changes['currency'] = $currency;

            // Only hit the Currency API when the code changed or we have no symbol yet.
            if ($setting->currency !== $currency || ! $setting->currency_symbol) {
                [$symbol, $precision] = $this->currencyFormat($currency);
                $changes['currency_symbol'] = $symbol;
                if ($precision !== null) {
                    $changes['currency_precision'] = $precision;
                }
            }
        }

        if (! $changes) {
            return;
        }

        $setting->fill($changes)->save();
        Setting::flushCache();
    }

    /**
     * Symbol + decimal precision for an ERPNext currency.
     *
     * @return array{0: string, 1: int|null}
     */
    protected function currencyFormat(string $code): array
    {
        $symbol = '';
        $precision = null;

        try {
            $doc = $this->client->doc('Currency', $code);

            $symbol = trim((string) ($doc['symbol'] ?? ''));

            // ERPNext stores the display mask in `number_format` (e.g. "#,###.##").
            $format = (string) ($doc['number_format'] ?? '');
            if ($format !== '' && preg_match('/\.([#0]+)/', $format, $m)) {
                $precision = strlen($m[1]);
            } elseif ($format !== '') {
                $precision = 0;
            }
        } catch (Throwable $e) {
            Log::warning('PosProfileSync: currency format not synced', [
                'currency' => $code,
                'error' => $e->getMessage(),
            ]);
        }

        return [$symbol !== '' ? $symbol : PosProfile::symbolFor($code), $precision];
    }

    /**
     * Re-pull only the profiles this terminal serves (scheduler / CLI default).
     *
     * @return array{synced: int, errors: array<int, string>}
     */
    public function syncEnabled(): array
    {
        $names = PosProfile::whereNotNull('erpnext_id')->where('device_enabled', true)->pluck('erpnext_id');
        $synced = 0;
        $errors = [];
        foreach ($names as $name) {
            try {
                $this->syncOne($name);
                $synced++;
            } catch (Throwable $e) {
                $errors[] = "{$name}: ".$e->getMessage();
            }
        }

        return ['synced' => $synced, 'errors' => $errors];
    }

    /**
     * ERPNext "Applicable for Users" → local users + pos_profile_users.
     *
     * Mirrors ERPNext: an empty list means "everyone" (no rows attached).
     * Users we have never seen are created as cashiers with an unusable random
     * password and NO PIN — a manager sets the PIN on the Users page.
     */
    protected function importUsers(PosProfile $profile): void
    {
        $pivot = [];
        foreach ($profile->applicableUsers() as $erpUser => $isDefault) {
            $user = User::where('email', $erpUser)->orWhere('erpnext_id', $erpUser)->first();
            if (! $user) {
                $user = User::create([
                    'name' => Str::of($erpUser)->before('@')->replace(['.', '_'], ' ')->title()->toString() ?: $erpUser,
                    'email' => $erpUser,
                    'password' => Str::random(40),
                    'role' => User::ROLE_CASHIER,
                    'is_active' => true,
                    'erpnext_id' => $erpUser,
                ]);
            }
            $pivot[$user->id] = ['is_default' => $isDefault];
        }

        $profile->users()->sync($pivot);
    }
}
