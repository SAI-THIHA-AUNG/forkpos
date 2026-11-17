<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PosProfile;
use App\Models\Setting;
use App\Models\Tax;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosProfileController extends Controller
{
    /**
     * GET /api/v1/pos-profiles — profiles the caller may use, with payments.
     */
    public function index(Request $request): JsonResponse
    {
        $profiles = PosProfile::visibleTo($request->user())
            ->with(['payments', 'warehouse'])
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $profiles->map(fn (PosProfile $p) => $this->payload($p))]);
    }

    /**
     * GET /api/v1/pos-profiles/{id} — full profile config for the POS screen.
     */
    public function show(int $id): JsonResponse
    {
        $profile = PosProfile::with(['payments', 'warehouse', 'users'])->findOrFail($id);

        $setting = Setting::current();

        return response()->json([
            'data' => array_merge($this->payload($profile), [
                'settings' => [
                    'company_name' => $setting->company_name,
                    'currency' => $profile->effectiveCurrency($setting),
                    'currency_symbol' => $profile->effectiveCurrencySymbol($setting),
                    'currency_precision' => $profile->effectivePrecision($setting),
                    'tax_inclusive_prices' => (bool) $setting->tax_inclusive_prices,
                    'allow_negative_stock' => (bool) $setting->allow_negative_stock,
                    'receipt_header' => $setting->receipt_header,
                    'receipt_footer' => $setting->receipt_footer,
                ],
                'default_taxes' => Tax::where('is_default', true)->get(['id', 'name', 'rate', 'included_in_price'])->map(fn ($t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'rate' => (float) $t->rate,
                    'included_in_price' => (bool) $t->included_in_price,
                ]),
            ]),
        ]);
    }

    /**
     * POST /api/v1/pos-profiles — admin/manager creates a local profile.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64', 'unique:pos_profiles,name'],
            'company' => ['nullable', 'string', 'max:128'],
            'currency' => ['nullable', 'string', 'max:8'],
            'warehouse_id' => ['nullable', 'exists:warehouses,id'],
            'selling_price_list' => ['nullable', 'string', 'max:64'],
            'allow_rate_change' => ['nullable', 'boolean'],
            'allow_discount_change' => ['nullable', 'boolean'],
            'allow_negative_stock' => ['nullable', 'boolean'],
            'tax_inclusive' => ['nullable', 'boolean'],
            'write_off_limit' => ['nullable', 'numeric', 'min:0'],
            'max_discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'ask_for_customer' => ['nullable', 'boolean'],
            'require_shift' => ['nullable', 'boolean'],
            'is_default' => ['nullable', 'boolean'],
            'payments' => ['required', 'array', 'min:1'],
            'payments.*.mode_of_payment' => ['required', 'string', 'max:64'],
            'payments.*.is_default' => ['nullable', 'boolean'],
            'payments.*.allow_edit_amount' => ['nullable', 'boolean'],
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        $profile = PosProfile::create(collect($data)->except(['payments', 'user_ids'])->all());
        foreach ($data['payments'] as $payment) {
            $profile->payments()->create([
                'mode_of_payment' => $payment['mode_of_payment'],
                'is_default' => $payment['is_default'] ?? false,
                'allow_edit_amount' => $payment['allow_edit_amount'] ?? true,
            ]);
        }
        if (! empty($data['user_ids'])) {
            $profile->users()->sync($data['user_ids']);
        }

        return response()->json(['data' => $this->payload($profile->load(['payments', 'warehouse']))], 201);
    }

    /**
     * PUT /api/v1/pos-profiles/{id} — update a local profile.
     *
     * ERPNext-synced profiles (erpnext_id IS NOT NULL) reject edits to their
     * ERPNext-owned fields (currency, warehouse_id, selling_price_list,
     * payments). Those fields are owned by ERPNext and would be overwritten on
     * the next `forkpos:sync-profiles` run — so accepting them silently would
     * create data the cashier can't rely on.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $profile = PosProfile::findOrFail($id);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:64', 'unique:pos_profiles,name,'.$id],
            'company' => ['sometimes', 'nullable', 'string', 'max:128'],
            'currency' => ['sometimes', 'nullable', 'string', 'max:8'],
            'warehouse_id' => ['sometimes', 'nullable', 'exists:warehouses,id'],
            'selling_price_list' => ['sometimes', 'nullable', 'string', 'max:64'],
            'allow_rate_change' => ['sometimes', 'boolean'],
            'allow_discount_change' => ['sometimes', 'boolean'],
            'allow_negative_stock' => ['sometimes', 'boolean'],
            'tax_inclusive' => ['sometimes', 'boolean'],
            'write_off_limit' => ['sometimes', 'numeric', 'min:0'],
            'max_discount_percentage' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'ask_for_customer' => ['sometimes', 'boolean'],
            'require_shift' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'disabled' => ['sometimes', 'boolean'],
            'payments' => ['sometimes', 'array'],
            'payments.*.mode_of_payment' => ['required_with:payments', 'string', 'max:64'],
            'payments.*.is_default' => ['sometimes', 'boolean'],
            'payments.*.allow_edit_amount' => ['sometimes', 'boolean'],
            'user_ids' => ['sometimes', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
        ]);

        // ERPNext-owned fields — block client edits on synced profiles.
        if ($profile->isErpnextSynced()) {
            $locked = array_intersect_key(
                $data,
                array_flip(['currency', 'warehouse_id', 'selling_price_list', 'payments', 'company'])
            );

            if (! empty($locked)) {
                return response()->json([
                    'data' => [
                        'message' => 'Profile "'.$profile->name.'" is ERPNext-synced. '
                            .'These fields are owned by ERPNext and cannot be edited locally: '
                            .implode(', ', array_keys($locked))
                            .'. Edit them in ERPNext, then run `php artisan forkpos:sync-profiles`.',
                    ],
                ], 422);
            }
        }

        $profile->update(collect($data)->except(['payments', 'user_ids'])->all());

        if (isset($data['payments']) && ! $profile->isErpnextSynced()) {
            $profile->payments()->delete();
            foreach ($data['payments'] as $payment) {
                $profile->payments()->create([
                    'mode_of_payment' => $payment['mode_of_payment'],
                    'is_default' => $payment['is_default'] ?? false,
                    'allow_edit_amount' => $payment['allow_edit_amount'] ?? true,
                ]);
            }
        }

        if (! empty($data['user_ids'])) {
            $profile->users()->sync($data['user_ids']);
        }

        return response()->json(['data' => $this->payload($profile->fresh(['payments', 'warehouse']))]);
    }

    /**
     * Map a PosProfile to an API response shape.
     */
    protected function payload(PosProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'name' => $profile->name,
            'company' => $profile->company,
            'currency' => $profile->currency,
            'warehouse' => $profile->warehouse?->name,
            'warehouse_id' => $profile->warehouse_id,
            'selling_price_list' => $profile->selling_price_list,
            'allow_rate_change' => (bool) $profile->allow_rate_change,
            'allow_discount_change' => (bool) $profile->allow_discount_change,
            'allow_negative_stock' => (bool) $profile->allow_negative_stock,
            'tax_inclusive' => (bool) $profile->tax_inclusive,
            'write_off_limit' => (float) $profile->write_off_limit,
            'max_discount_percentage' => (float) $profile->max_discount_percentage,
            'ask_for_customer' => (bool) $profile->ask_for_customer,
            'require_shift' => (bool) $profile->require_shift,
            'is_default' => (bool) $profile->is_default,
            'payments' => $profile->payments->map(fn ($pay) => [
                'mode_of_payment' => $pay->mode_of_payment,
                'is_default' => (bool) $pay->is_default,
                'allow_edit_amount' => (bool) $pay->allow_edit_amount,
            ]),
            'erpnext_id' => $profile->erpnext_id,
            'erpnext_synced' => $profile->isErpnextSynced(),
            'synced_at' => $profile->synced_at?->toDateTimeString(),
        ];
    }
}
