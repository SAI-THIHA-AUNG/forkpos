<?php

namespace App\Http\Controllers\Api\V1;

use App\Console\Commands\SyncMastersCommand;
use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Models\Setting;
use App\Models\Tax;
use App\Services\CouponService;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    /**
     * GET /api/v1/settings — app settings (secrets masked).
     */
    public function show()
    {
        $setting = Setting::current();
        $user = request()->user();

        return response()->json([
            'data' => [
                'company_name' => $setting->company_name,
                'currency' => $setting->currency,
                'currency_symbol' => $setting->currency_symbol,
                'currency_precision' => $setting->currency_precision,
                'tax_inclusive_prices' => (bool) $setting->tax_inclusive_prices,
                'allow_negative_stock' => (bool) $setting->allow_negative_stock,
                'invoice_prefix' => $setting->invoice_prefix,
                'receipt_header' => $setting->receipt_header,
                'receipt_footer' => $setting->receipt_footer,
                'last_synced_at' => $setting->last_synced_at?->toDateTimeString(),
                'erpnext_url' => $setting->erpnext_url,
                'erpnext_company' => $setting->erpnext_company,
                'erpnext_price_list' => $setting->erpnext_price_list,
                // Secrets are only ever written, never read back.
                'erpnext_api_key' => $setting->erpnext_api_key ? '***set***' : null,
                'erpnext_api_secret' => $setting->erpnext_api_secret ? '***set***' : null,
                'erpnext_configured' => (bool) ($setting->erpnext_url && $setting->erpnext_api_key && $setting->erpnext_api_secret),
                'can_manage' => $user?->isManager() ?? false,
            ],
        ]);
    }

    /**
     * PUT /api/v1/settings — manager/admin only.
     */
    public function update(Request $request)
    {
        abort_unless($request->user()->isManager(), 403, 'Only managers can update settings.');

        $data = $request->validate([
            'company_name' => ['sometimes', 'string', 'max:128'],
            'currency' => ['sometimes', 'string', 'max:8'],
            'currency_symbol' => ['sometimes', 'string', 'max:8'],
            'currency_precision' => ['sometimes', 'integer', 'min:0', 'max:6'],
            'tax_inclusive_prices' => ['sometimes', 'boolean'],
            'allow_negative_stock' => ['sometimes', 'boolean'],
            'invoice_prefix' => ['sometimes', 'string', 'max:16'],
            'receipt_header' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'receipt_footer' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'erpnext_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'erpnext_api_key' => ['sometimes', 'nullable', 'string', 'max:128'],
            'erpnext_api_secret' => ['sometimes', 'nullable', 'string', 'max:255'],
            'erpnext_company' => ['sometimes', 'nullable', 'string', 'max:128'],
            'erpnext_price_list' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        $setting = Setting::current();
        $setting->fill($data)->save();
        Setting::flushCache();

        // Check if ERPNext credentials were just set/updated
        $erpnextConfigured = ! empty($setting->erpnext_url) && ! empty($setting->erpnext_api_key) && ! empty($setting->erpnext_api_secret);

        if ($erpnextConfigured) {
            try {
                // Sync initial data from ERPNext
                SyncMastersCommand::syncInitialData();

                // Create or update POS profiles with ERPNext warehouses
                SyncMastersCommand::updatePosProfiles();
            } catch (\Exception $e) {
                // Log the error but don't fail the settings update
                \Log::error('ERPNext sync failed after settings update: '.$e->getMessage());
            }
        }

        return response()->json(['data' => ['message' => 'Settings updated.'.($erpnextConfigured ? ' Data synced from ERPNext.' : '')]]);
    }

    /**
     * GET /api/v1/taxes — tax templates available for invoices.
     */
    public function taxes()
    {
        return response()->json([
            'data' => Tax::orderByDesc('is_default')->orderBy('name')->get()->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'description' => $t->description,
                'rate' => (float) $t->rate,
                'included_in_price' => (bool) $t->included_in_price,
                'is_default' => (bool) $t->is_default,
                'account_name' => $t->account_name,
            ]),
        ]);
    }

    /**
     * GET /api/v1/coupons/check?code=X&amount=Y
     */
    public function checkCoupon(Request $request, CouponService $coupons)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'amount' => ['required', 'numeric', 'min:0'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
        ]);

        $coupon = Coupon::where('coupon_code', $data['code'])->first();
        if (! $coupon) {
            return response()->json([
                'data' => ['valid' => false, 'discount' => 0.0, 'message' => 'Coupon code is not valid.'],
            ]);
        }

        return response()->json([
            'data' => $coupons->check($coupon, (float) $data['amount'], $data['customer_id'] ?? null),
        ]);
    }
}
