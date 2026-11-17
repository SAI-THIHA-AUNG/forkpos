@extends('layouts.app')
@section('title', 'Settings')

@section('content')
<div class="max-w-3xl mx-auto">
    <h1 class="text-xl font-bold text-slate-800 mb-4">Settings</h1>
    @include('pos.partials.flash')

    @php
        $currencyCode = $activeProfile?->effectiveCurrency($setting) ?: $setting->currency;
        $currencySymbol = $setting->currency_symbol ?: ($activeProfile?->effectiveCurrencySymbol($setting) ?: $currencyCode);
        $currencyPrecision = $activeProfile?->effectivePrecision($setting) ?? ($setting->currency_precision ?? 2);
        $priceList = $activeProfile?->selling_price_list ?: $setting->erpnext_price_list;
        $taxInclusive = $activeProfile ? (bool) $activeProfile->tax_inclusive : (bool) $setting->tax_inclusive_prices;
        $negativeStock = $activeProfile ? (bool) $activeProfile->allow_negative_stock : (bool) $setting->allow_negative_stock;
    @endphp

    {{-- Everything ERPNext owns. No manual entry: the values arrive with the
         POS Profile sync and are re-mirrored into settings on every sync. --}}
    <div class="bg-white rounded-xl border border-slate-200 p-6 mb-6">
        <div class="flex items-start justify-between gap-3">
            <div>
                <h2 class="font-semibold text-slate-700">Store defaults</h2>
                <p class="text-xs text-slate-500 mt-0.5">
                    Read-only &mdash; sourced from the ERPNext POS Profile. Re-sync to refresh.
                </p>
            </div>
            @if ($setting->erpnextEnabled())
            <form method="POST" action="{{ route('settings.sync-pos-profile') }}">
                @csrf
                @if ($activeProfile)
                <input type="hidden" name="name" value="{{ $activeProfile->name }}">
                @endif
                <button class="px-3 py-1.5 rounded-lg border border-slate-300 text-slate-700 text-sm font-medium whitespace-nowrap">Re-sync now</button>
            </form>
            @endif
        </div>

        @if (! $activeProfile)
            <p class="mt-4 text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
                No ERPNext POS profile synced yet. Configure the connector below, then sync a profile &mdash;
                company, currency and price list fill in automatically.
            </p>
        @endif

        <dl class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-3 text-sm">
            <div>
                <dt class="text-xs uppercase tracking-wide text-slate-400">Company</dt>
                <dd class="font-medium text-slate-800">{{ $activeProfile?->company ?: ($setting->company_name ?: '—') }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-slate-400">POS profile</dt>
                <dd class="font-medium text-slate-800">
                    {{ $activeProfile?->name ?? '—' }}
                    @if ($activeProfile)
                    <span class="ml-1 text-[10px] px-1.5 py-0.5 rounded bg-green-100 text-green-700 align-middle">ERPNext</span>
                    @endif
                </dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-slate-400">Currency</dt>
                <dd class="font-medium text-slate-800">{{ $currencyCode ?: '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-slate-400">Currency symbol / precision</dt>
                <dd class="font-medium text-slate-800">{{ $currencySymbol ?: '—' }} &middot; {{ $currencyPrecision }} decimals</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-slate-400">Selling price list</dt>
                <dd class="font-medium text-slate-800">{{ $priceList ?: '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-slate-400">Warehouse</dt>
                <dd class="font-medium text-slate-800">{{ $activeProfile?->warehouse?->name ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-slate-400">Tax-inclusive prices</dt>
                <dd class="font-medium {{ $taxInclusive ? 'text-slate-800' : 'text-slate-500' }}">{{ $taxInclusive ? 'Yes' : 'No' }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-slate-400">Allow negative stock</dt>
                <dd class="font-medium {{ $negativeStock ? 'text-slate-800' : 'text-slate-500' }}">{{ $negativeStock ? 'Yes' : 'No' }}</dd>
            </div>
        </dl>

        @if ($activeProfile)
        <div class="mt-4 pt-4 border-t border-slate-100 flex flex-wrap gap-x-6 gap-y-2 text-xs text-slate-500">
            <span>Price change: <strong class="{{ $activeProfile->allow_rate_change ? 'text-green-700' : 'text-red-600' }}">{{ $activeProfile->allow_rate_change ? 'allowed' : 'locked' }}</strong></span>
            <span>Discount: <strong class="{{ $activeProfile->allow_discount_change ? 'text-green-700' : 'text-red-600' }}">{{ $activeProfile->allow_discount_change ? 'allowed' : 'locked' }}</strong></span>
            <span>Max discount: <strong class="text-slate-700">{{ $activeProfile->max_discount_percentage > 0 ? $activeProfile->max_discount_percentage.'%' : 'unlimited' }}</strong></span>
            <span>Payment methods: <strong class="text-slate-700">{{ $activeProfile->payments->pluck('mode_of_payment')->join(', ') ?: '—' }}</strong></span>
            <span>Last sync: <strong class="text-slate-700">{{ $activeProfile->synced_at?->diffForHumans() ?? '—' }}</strong></span>
        </div>
        @endif
    </div>

    <form method="POST" action="{{ route('settings.update') }}" class="space-y-6">
        @csrf @method('PUT')

        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <h2 class="font-semibold text-slate-700 mb-1">Invoicing &amp; receipt</h2>
            <p class="text-xs text-slate-500 mb-3">Local terminal preferences &mdash; these are not stored in ERPNext.</p>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Invoice prefix</label>
                    <input type="text" name="invoice_prefix" value="{{ old('invoice_prefix', $setting->invoice_prefix) }}" placeholder="POS-" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                </div>
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Receipt header</label>
                    <input type="text" name="receipt_header" value="{{ old('receipt_header', $setting->receipt_header) }}" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                </div>
                <div class="col-span-2">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Receipt footer</label>
                    <input type="text" name="receipt_footer" value="{{ old('receipt_footer', $setting->receipt_footer) }}" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                </div>

                @unless ($activeProfile)
                {{-- Local fallbacks, used only until an ERPNext profile is synced. --}}
                <label class="flex items-center gap-2 text-sm col-span-2">
                    <input type="checkbox" name="tax_inclusive_prices" value="1" @checked(old('tax_inclusive_prices', $setting->tax_inclusive_prices)) class="rounded border-slate-300">
                    Tax-inclusive prices (tax baked into item rates by default)
                </label>
                <label class="flex items-center gap-2 text-sm col-span-2">
                    <input type="checkbox" name="allow_negative_stock" value="1" @checked(old('allow_negative_stock', $setting->allow_negative_stock)) class="rounded border-slate-300">
                    Allow negative stock (sell past zero balance)
                </label>
                @endunless
            </div>
        </div>

        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <h2 class="font-semibold text-slate-700 mb-1">ERPNext connector</h2>
            <p class="text-xs text-slate-500 mb-3">
                The API secret is encrypted at rest. Leave the secret blank to keep the current value.
                Company and price list are taken from the synced POS Profile &mdash; nothing to type here.
            </p>
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
                        <td class="text-center py-2">{{ $t->is_default ? 'default' : '' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="flex justify-end">
            <button class="px-6 py-2 rounded-lg bg-brand-600 hover:bg-brand-700 text-white font-medium">Save settings</button>
        </div>
    </form>

    {{-- ERPNext POS Profiles — pulled via App\Services\PosProfileSync --}}
    <div class="bg-white rounded-xl border border-slate-200 p-6 mt-6">
        <div class="flex items-center justify-between mb-3">
            <div>
                <h2 class="font-semibold text-slate-700">ERPNext POS Profiles</h2>
                <p class="text-xs text-slate-500 mt-0.5">
                    Pulled from ERPNext. ERPNext is the source of truth for currency, warehouse
                    &amp; payment methods &mdash; local edits of synced profiles are blocked.
                </p>
            </div>
            @if ($setting->erpnextEnabled())
            <a href="{{ route('setup.profiles') }}" class="px-4 py-2 rounded-lg border border-slate-300 text-slate-700 text-sm font-medium mr-2">Choose profiles for this terminal</a>
            <form method="POST" action="{{ route('settings.sync-pos-profile') }}" class="inline">
                @csrf
                <button class="px-4 py-2 rounded-lg bg-slate-800 hover:bg-slate-900 text-white text-sm font-medium">Sync all from ERPNext</button>
            </form>
            @endif
        </div>

        @if ($erpProfiles->isEmpty())
            <p class="text-sm text-slate-500">No ERPNext POS profiles synced yet. Configure the connector above, then click "Sync all from ERPNext".</p>
        @else
            <table class="w-full text-sm">
                <thead><tr class="text-xs text-slate-500 uppercase tracking-wide border-b border-slate-100">
                    <th class="text-left py-2">Profile</th><th class="text-left py-2">Currency</th>
                    <th class="text-left py-2">Price list</th><th class="text-left py-2">Warehouse</th>
                    <th class="text-left py-2">Payment methods</th>
                    <th class="text-right py-2">Synced</th>
                </tr></thead>
                <tbody>
                    @foreach ($erpProfiles as $p)
                    <tr class="border-b border-slate-50">
                        <td class="py-2"><p class="font-medium">{{ $p->name }}</p><p class="text-xs text-slate-400">{{ $p->company }}</p></td>
                        <td class="py-2">{{ $p->currency ?: '—' }}</td>
                        <td class="py-2">{{ $p->selling_price_list ?: '—' }}</td>
                        <td class="py-2">{{ $p->warehouse?->name ?? 'not synced' }}</td>
                        <td class="py-2">{{ $p->payments->pluck('mode_of_payment')->join(', ') ?: '—' }}</td>
                        <td class="py-2 text-right text-xs text-slate-500">{{ $p->synced_at?->diffForHumans() ?? '—' }}</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
@endsection
