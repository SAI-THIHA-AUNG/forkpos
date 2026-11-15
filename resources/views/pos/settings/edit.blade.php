@extends('layouts.app')
@section('title', 'Settings')

@section('content')
<div class="max-w-3xl mx-auto">
    <h1 class="text-xl font-bold text-slate-800 mb-4">Settings</h1>
    @include('pos.partials.flash')

    <form method="POST" action="{{ route('settings.update') }}" class="space-y-6">
        @csrf @method('PUT')

        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <h2 class="font-semibold text-slate-700 mb-3">Company &amp; currency</h2>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Company name</label>
                    <input type="text" name="company_name" value="{{ old('company_name', $setting->company_name) }}" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Currency</label>
                    <input type="text" name="currency" value="{{ old('currency', $setting->currency) }}" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Currency symbol</label>
                    <input type="text" name="currency_symbol" value="{{ old('currency_symbol', $setting->currency_symbol) }}" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Currency precision</label>
                    <input type="number" name="currency_precision" min="0" max="6" value="{{ old('currency_precision', $setting->currency_precision) }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 no-spin">
                </div>
                <label class="flex items-center gap-2 text-sm col-span-2">
                    <input type="checkbox" name="tax_inclusive_prices" value="1" @checked(old('tax_inclusive_prices', $setting->tax_inclusive_prices)) class="rounded border-slate-300">
                    Tax-inclusive prices (tax is baked into item rates by default)
                </label>
                <label class="flex items-center gap-2 text-sm col-span-2">
                    <input type="checkbox" name="allow_negative_stock" value="1" @checked(old('allow_negative_stock', $setting->allow_negative_stock)) class="rounded border-slate-300">
                    Allow negative stock (sell past zero balance)
                </label>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Invoice prefix</label>
                    <input type="text" name="invoice_prefix" value="{{ old('invoice_prefix', $setting->invoice_prefix) }}" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <h2 class="font-semibold text-slate-700 mb-3">Receipt</h2>
            <div class="space-y-3">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Receipt header</label>
                    <textarea name="receipt_header" rows="2" class="w-full rounded-lg border border-slate-300 px-3 py-2">{{ old('receipt_header', $setting->receipt_header) }}</textarea>
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Receipt footer</label>
                    <textarea name="receipt_footer" rows="2" class="w-full rounded-lg border border-slate-300 px-3 py-2">{{ old('receipt_footer', $setting->receipt_footer) }}</textarea>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <h2 class="font-semibold text-slate-700 mb-1">ERPNext connector</h2>
            <p class="text-xs text-slate-500 mb-3">The API secret is encrypted at rest. Leave the secret field blank to keep the existing value; otherwise it will be overwritten.</p>
            <div class="grid grid-cols-2 gap-4">
                <div class="col-span-2">
                    <label class="block text-sm font-medium text-slate-700 mb-1">ERPNext URL</label>
                    <input type="url" name="erpnext_url" value="{{ old('erpnext_url', $setting->erpnext_url) }}" placeholder="https://myerp.erpnext.com" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">API key</label>
                    <input type="text" name="erpnext_api_key" value="{{ old('erpnext_api_key', $setting->erpnext_api_key) }}" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">API secret</label>
                    <input type="password" name="erpnext_api_secret" placeholder="{{ $setting->erpnext_api_secret ? '*** set — leave blank to keep ***' : '' }}" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Company (ERPNext)</label>
                    <input type="text" name="erpnext_company" value="{{ old('erpnext_company', $setting->erpnext_company) }}" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Price list</label>
                    <input type="text" name="erpnext_price_list" value="{{ old('erpnext_price_list', $setting->erpnext_price_list) }}" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <h2 class="font-semibold text-slate-700 mb-3">Tax templates</h2>
            <table class="w-full text-sm">
                <thead><tr class="text-xs text-slate-500 uppercase tracking-wide border-b border-slate-100">
                    <th class="text-left py-2">Name</th><th class="text-right py-2">Rate</th><th class="text-center py-2">Included</th><th class="text-center py-2">Default</th>
                </tr></thead>
                <tbody>
                    @foreach ($taxes as $t)
                    <tr class="border-b border-slate-50">
                        <td class="py-2"><p class="font-medium">{{ $t->name }}</p><p class="text-xs text-slate-400">{{ $t->description }}</p></td>
                        <td class="text-right py-2">{{ number_format($t->rate, 2) }}%</td>
                        <td class="text-center py-2">{{ $t->included_in_price ? 'Yes' : 'No' }}</td>
                        <td class="text-center py-2">{{ $t->is_default ? '✓' : '' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="flex justify-end">
            <button class="px-6 py-2 rounded-lg bg-brand-600 hover:bg-brand-700 text-white font-medium">Save settings</button>
        </div>
    </form>
</div>
@endsection
