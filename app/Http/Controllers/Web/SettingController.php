<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\PosProfile;
use App\Models\Setting;
use App\Models\Tax;
use App\Services\ErpNextClient;
use App\Services\PosProfileSync;
use Illuminate\Http\Request;
use Throwable;

class SettingController extends Controller
{
    public function __construct(protected PosProfileSync $profileSync) {}

    public function edit()
    {
        $setting = Setting::current();
        $taxes = Tax::orderByDesc('is_default')->orderBy('name')->get();

        // ERPNext-sourced POS profiles — the cards shown under the "Sync POS
        // Profiles" section. Empty when the connector isn't configured yet.
        $erpProfiles = PosProfile::whereNotNull('erpnext_id')
            ->with(['payments', 'warehouse'])
            ->orderByDesc('synced_at')
            ->orderBy('name')
            ->get();

        // The profile the settings page reports on: the cashier's selection,
        // or the most recently synced profile.
        $activeProfile = session('pos_profile_id')
            ? $erpProfiles->firstWhere('id', (int) session('pos_profile_id')) ?? $erpProfiles->first()
            : $erpProfiles->first();

        return view('pos.settings.edit', [
            'setting' => $setting,
            'taxes' => $taxes,
            'erpProfiles' => $erpProfiles,
            'activeProfile' => $activeProfile,
            'nextInvoiceNo' => ($setting->invoice_prefix ?: 'POS-').now()->year.'-'
                .str_pad((string) $setting->next_invoice_number, 5, '0', STR_PAD_LEFT),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'company_name' => ['sometimes', 'string', 'max:128'],
            'currency' => ['sometimes', 'string', 'max:8'],
            'currency_symbol' => ['sometimes', 'string', 'max:8'],
            'currency_precision' => ['sometimes', 'integer', 'min:0', 'max:6'],
            'tax_inclusive_prices' => ['sometimes', 'boolean'],
            'allow_negative_stock' => ['sometimes', 'boolean'],
            'receipt_header' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'receipt_footer' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'erpnext_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'erpnext_api_key' => ['sometimes', 'nullable', 'string', 'max:128'],
            'erpnext_api_secret' => ['sometimes', 'nullable', 'string', 'max:255'],
            'erpnext_company' => ['sometimes', 'nullable', 'string', 'max:128'],
            'erpnext_price_list' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        $setting = Setting::current();

        // Detect a change to the ERPNext connector config — if anything in the
        // erpnext_* block changed (and the connector is now enabled), we trigger
        // an immediate POS Profile pull so the cashier sees the right currency /
        // warehouse / payment methods without having to run the schedule:work.
        $connectorChanged = collect(['erpnext_url', 'erpnext_api_key', 'erpnext_api_secret', 'erpnext_company'])
            ->contains(fn ($k) => array_key_exists($k, $data) && (string) $data[$k] !== (string) $setting->{$k});

        // Don't overwrite an existing secret with an empty submission (the form
        // masks secrets as '***set***'; empty means "leave as-is").
        if (empty($data['erpnext_api_secret'])) {
            unset($data['erpnext_api_secret']);
        }
        $setting->fill($data)->save();
        Setting::flushCache();

        $messages = ['Settings updated.'];

        // Connector changed: the new key/secret must be proven before anything uses it.
        if ($connectorChanged) {
            $setting->forceFill(['erpnext_verified_at' => null])->save();
            if ($setting->erpnextEnabled()) {
                try {
                    $erpUser = app(ErpNextClient::class)->verify()['user'];
                    $setting->forceFill(['erpnext_verified_at' => now()])->save();
                    $messages[] = "Connected to ERPNext as {$erpUser}.";
                } catch (Throwable $e) {
                    Setting::flushCache();

                    return back()->with('error', 'Settings saved, but ERPNext rejected the credentials: '.$e->getMessage());
                }
            }
            Setting::flushCache();
        }

        return back()->with('success', implode(' ', $messages));
    }

    /**
     * POST /settings/sync-pos-profile — manually trigger a POS Profile pull
     * from ERPNext. Returns to the settings page with a flash message.
     * Used by the "Sync POS Profiles from ERPNext" button.
     */
    public function syncPosProfile(Request $request)
    {
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:140'],
        ]);

        try {
            if ($name = $data['name'] ?? null) {
                $profile = $this->profileSync->syncOne($name);

                return back()->with('success', sprintf(
                    'Synced "%s" — currency=%s, warehouse=%s, payments=%d, invoice series=%s.',
                    $profile->name,
                    $profile->currency ?: '(none)',
                    $profile->warehouse?->name ?? '(none)',
                    $profile->payments()->count(),
                    Setting::current()->invoice_series ?: '(none)',
                ));
            }

            $result = $this->profileSync->syncAll();

            return back()->with(
                $result['errors'] ? 'warning' : 'success',
                sprintf(
                    'Synced %d POS Profile(s) from ERPNext.%s',
                    $result['synced'],
                    $result['errors'] ? ' Errors: '.implode(' | ', array_slice($result['errors'], 0, 3)) : '',
                ),
            );
        } catch (Throwable $e) {
            return back()->with('error', 'POS Profile sync failed: '.$e->getMessage());
        }
    }
}
