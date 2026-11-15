<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\Tax;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function edit()
    {
        $setting = Setting::current();
        $taxes = Tax::orderByDesc('is_default')->orderBy('name')->get();

        return view('pos.settings.edit', [
            'setting' => $setting,
            'taxes' => $taxes,
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
        // Don't overwrite an existing secret with an empty submission (the form
        // masks secrets as '***set***'; empty means "leave as-is").
        if (empty($data['erpnext_api_secret'])) {
            unset($data['erpnext_api_secret']);
        }
        $setting->fill($data)->save();
        Setting::flushCache();

        return back()->with('success', 'Settings updated.');
    }
}
