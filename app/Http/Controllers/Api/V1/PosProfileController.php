<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\PosProfile;
use App\Models\Setting;
use App\Models\Tax;
use Illuminate\Http\Request;

class PosProfileController extends Controller
{
    /**
     * GET /api/v1/pos-profiles — profiles the caller may use, with payments.
     */
    public function index(Request $request)
    {
        $profiles = PosProfile::where('disabled', false)
            ->when(! $request->user()->isManager(), function ($q) use ($request) {
                $q->whereHas('users', fn ($u) => $u->where('users.id', $request->user()->id));
            })
            ->with(['payments', 'warehouse'])
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return response()->json(['data' => $profiles->map(fn ($p) => $this->payload($p))]);
    }

    /**
     * GET /api/v1/pos-profiles/{id} — full profile config for the POS screen.
     */
    public function show(int $id)
    {
        $profile = PosProfile::with(['payments', 'warehouse', 'users'])->findOrFail($id);

        $setting = Setting::current();

        return response()->json([
            'data' => array_merge($this->payload($profile), [
                'settings' => [
                    'company_name' => $setting->company_name,
                    'currency' => $setting->currency,
                    'currency_symbol' => $setting->currency_symbol,
                    'currency_precision' => $setting->currency_precision,
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
    public function store(Request $request)
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

    protected function payload(PosProfile $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'company' => $p->company,
            'currency' => $p->currency,
            'warehouse' => $p->warehouse?->name,
            'warehouse_id' => $p->warehouse_id,
            'selling_price_list' => $p->selling_price_list,
            'allow_rate_change' => (bool) $p->allow_rate_change,
            'allow_discount_change' => (bool) $p->allow_discount_change,
            'allow_negative_stock' => (bool) $p->allow_negative_stock,
            'tax_inclusive' => (bool) $p->tax_inclusive,
            'write_off_limit' => (float) $p->write_off_limit,
            'max_discount_percentage' => (float) $p->max_discount_percentage,
            'ask_for_customer' => (bool) $p->ask_for_customer,
            'require_shift' => (bool) $p->require_shift,
            'is_default' => (bool) $p->is_default,
            'payments' => $p->payments->map(fn ($pay) => [
                'mode_of_payment' => $pay->mode_of_payment,
                'is_default' => (bool) $pay->is_default,
                'allow_edit_amount' => (bool) $pay->allow_edit_amount,
            ]),
        ];
    }
}
