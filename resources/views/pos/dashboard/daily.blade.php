@extends('layouts.app')
@section('title', 'Daily summary')

@php
    $currency = optional(request()->attributes->get('posProfile'))->effectiveCurrencySymbol() ?? '';
    $precision = 2;
    $money = fn ($n) => \App\Support\Money::format($n, $precision);
    $qtyFmt = fn ($n) => \App\Support\Money::quantity($n);
    $label = $from === $to ? \Illuminate\Support\Carbon::parse($from)->format('D, M j, Y') : $from.' → '.$to;
    $query = fn (array $extra = []) => array_merge(request()->only(['from', 'to', 'warehouse']), ['from' => $from, 'to' => $to], $extra);
@endphp

@section('content')
<div class="max-w-7xl mx-auto">
    <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
        <div>
            <h1 class="text-xl font-bold text-slate-800">Daily summary</h1>
            <p class="text-sm text-slate-500">{{ $label }}</p>
        </div>
        <div class="flex items-center gap-2">
            @if ($isManager)
            <a href="{{ route('dashboard.daily.export', $query(['warehouse' => $warehouseId ?? 'all'])) }}"
               class="rounded-lg bg-green-600 hover:bg-green-700 text-white text-sm font-medium px-4 py-2" title="Summary, invoices, line items and inventory movement">⬇ Export to Excel</a>
            @endif
            <a href="{{ route('dashboard') }}" class="text-sm text-slate-500 hover:text-slate-700">← Dashboard</a>
        </div>
    </div>
    @include('pos.partials.flash')

    <form method="GET" class="mb-4 flex flex-wrap items-end gap-2">
        <input type="hidden" name="tab" value="{{ $tab }}">
        <div><label class="block text-xs text-slate-500 mb-0.5">From</label>
            <input type="date" name="from" value="{{ $from }}" class="rounded-lg border border-slate-300 px-3 py-2"></div>
        <div><label class="block text-xs text-slate-500 mb-0.5">To</label>
            <input type="date" name="to" value="{{ $to }}" class="rounded-lg border border-slate-300 px-3 py-2"></div>
        @if ($isManager && $warehouses->isNotEmpty())
        <div><label class="block text-xs text-slate-500 mb-0.5">Warehouse (inventory)</label>
            <select name="warehouse" class="rounded-lg border border-slate-300 px-3 py-2 bg-white">
                <option value="all" @selected(! $warehouseId)>All warehouses</option>
                @foreach ($warehouses as $w)<option value="{{ $w->id }}" @selected($warehouseId === $w->id)>{{ $w->name }}</option>@endforeach
            </select></div>
        @endif
        <button class="rounded-lg bg-slate-200 hover:bg-slate-300 px-4 py-2 text-sm">Show</button>
    </form>

    {{-- Summary cards --}}
    <div class="grid grid-cols-2 md:grid-cols-4 xl:grid-cols-6 gap-3 mb-4">
        <div class="bg-white rounded-xl border border-slate-200 p-3">
            <p class="text-xs text-slate-500 uppercase tracking-wide">Sales ({{ $summary['sales_count'] }})</p>
            <p class="text-lg font-bold text-brand-700 mt-1">{{ $currency }}{{ $money($summary['sales_total']) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-3">
            <p class="text-xs text-slate-500 uppercase tracking-wide">Returns ({{ $summary['returns_count'] }})</p>
            <p class="text-lg font-bold text-red-600 mt-1">{{ $currency }}{{ $money(abs($summary['returns_total'])) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-3">
            <p class="text-xs text-slate-500 uppercase tracking-wide">Line discounts</p>
            <p class="text-lg font-bold text-slate-700 mt-1">{{ $currency }}{{ $money($summary['line_discounts']) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-3">
            <p class="text-xs text-slate-500 uppercase tracking-wide">Total discounts</p>
            <p class="text-lg font-bold text-slate-700 mt-1">{{ $currency }}{{ $money($summary['total_discounts']) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-3">
            <p class="text-xs text-slate-500 uppercase tracking-wide">Tax</p>
            <p class="text-lg font-bold text-slate-700 mt-1">{{ $currency }}{{ $money($summary['tax_total']) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-3">
            <p class="text-xs text-slate-500 uppercase tracking-wide">Net sales</p>
            <p class="text-lg font-bold text-green-700 mt-1">{{ $currency }}{{ $money($summary['grand_total']) }}</p>
        </div>
    </div>

    @if ($payments->isNotEmpty())
    <div class="bg-white rounded-xl border border-slate-200 p-3 mb-4 flex flex-wrap gap-x-6 gap-y-1 text-sm">
        <span class="text-xs text-slate-500 uppercase tracking-wide self-center">Payments</span>
        @foreach ($payments as $mode => $total)
        <span><span class="text-slate-500">{{ $mode }}</span> <span class="font-medium">{{ $currency }}{{ $money($total) }}</span></span>
        @endforeach
    </div>
    @endif

    {{-- Tabs --}}
    <div class="flex gap-1 mb-3 border-b border-slate-200">
        @foreach (array_filter(['invoices' => 'Invoices', 'lines' => 'Line items', 'stock' => $isManager ? 'Inventory movement' : null]) as $key => $title)
        <a href="{{ route('dashboard.daily', $query(['tab' => $key])) }}"
           class="px-4 py-2 text-sm -mb-px border-b-2 {{ $tab === $key ? 'border-brand-600 text-brand-700 font-medium' : 'border-transparent text-slate-500 hover:text-slate-700' }}">{{ $title }}</a>
        @endforeach
    </div>

    @if ($tab === 'invoices')
    <div class="bg-white rounded-xl border border-slate-200 overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="text-xs text-slate-500 uppercase tracking-wide border-b border-slate-100 bg-slate-50">
                <th class="text-left px-4 py-2">Invoice</th><th class="text-left px-4 py-2">Time</th><th class="text-left px-4 py-2">Customer</th><th class="text-left px-4 py-2">Cashier</th>
                <th class="text-center px-4 py-2">Status</th><th class="text-right px-4 py-2">Discount</th><th class="text-right px-4 py-2">Tax</th><th class="text-right px-4 py-2">Total</th>
            </tr></thead>
            <tbody>
                @forelse ($invoices as $inv)
                <tr class="border-b border-slate-50 hover:bg-slate-50">
                    <td class="px-4 py-2 font-mono text-xs"><a href="{{ route('invoices.show', $inv) }}" class="text-brand-600 hover:underline">{{ $inv->invoice_no }}</a>@if($inv->is_return)<span class="ml-1 text-[10px] px-1.5 py-0.5 rounded bg-amber-100 text-amber-700">RETURN</span>@endif</td>
                    <td class="px-4 py-2 text-slate-500">{{ $inv->posting_datetime?->format($from === $to ? 'H:i' : 'M j H:i') }}</td>
                    <td class="px-4 py-2">{{ $inv->customer?->customer_name ?? '—' }}</td>
                    <td class="px-4 py-2 text-slate-500">{{ $inv->cashier?->name ?? '—' }}</td>
                    <td class="px-4 py-2 text-center"><span class="text-xs px-2 py-0.5 rounded-full @if($inv->status==='paid')bg-green-100 text-green-700 @elseif($inv->status==='cancelled')bg-slate-200 text-slate-500 @else bg-amber-100 text-amber-700 @endif">{{ ucfirst(str_replace('_',' ',$inv->status)) }}</span></td>
                    <td class="px-4 py-2 text-right text-slate-500">{{ $inv->discount_total > 0 ? $money($inv->discount_total) : '—' }}</td>
                    <td class="px-4 py-2 text-right text-slate-500">{{ $inv->tax_total != 0 ? $money($inv->tax_total) : '—' }}</td>
                    <td class="px-4 py-2 text-right font-medium {{ $inv->is_return ? 'text-red-600' : '' }}">{{ $money($inv->grand_total) }}</td>
                </tr>
                @empty
                <tr><td colspan="8" class="px-4 py-8 text-center text-slate-400">No invoices for {{ $label }}.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $invoices->links() }}

    @elseif ($tab === 'lines')
    <div class="bg-white rounded-xl border border-slate-200 overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="text-xs text-slate-500 uppercase tracking-wide border-b border-slate-100 bg-slate-50">
                <th class="text-left px-3 py-2">Invoice</th><th class="text-left px-3 py-2">Time</th><th class="text-left px-3 py-2">Item</th>
                <th class="text-right px-3 py-2">Qty</th><th class="text-right px-3 py-2">List price</th><th class="text-right px-3 py-2">Rate paid</th>
                <th class="text-right px-3 py-2">Disc.</th><th class="text-right px-3 py-2">Tax</th><th class="text-right px-3 py-2">Amount</th>
            </tr></thead>
            <tbody>
                @forelse ($lines as $l)
                <tr class="border-b border-slate-50 hover:bg-slate-50">
                    <td class="px-3 py-1.5 font-mono text-xs"><a href="{{ route('invoices.show', $l->invoice_id) }}" class="text-brand-600 hover:underline">{{ $l->invoice_no }}</a>@if($l->is_return)<span class="ml-1 text-[10px] px-1 rounded bg-amber-100 text-amber-700">RET</span>@endif</td>
                    <td class="px-3 py-1.5 text-slate-500">{{ \Illuminate\Support\Carbon::parse($l->posting_datetime)->format($from === $to ? 'H:i' : 'M j H:i') }}</td>
                    <td class="px-3 py-1.5"><p class="font-medium">{{ $l->item_name }}</p><p class="text-xs text-slate-400">{{ $l->item_code }} · {{ $l->uom }}</p></td>
                    <td class="px-3 py-1.5 text-right {{ $l->qty < 0 ? 'text-red-600' : '' }}">{{ $qtyFmt($l->qty) }}</td>
                    <td class="px-3 py-1.5 text-right text-slate-500">{{ $money($l->price_list_rate) }}</td>
                    <td class="px-3 py-1.5 text-right">{{ $money($l->rate) }}</td>
                    <td class="px-3 py-1.5 text-right text-slate-500">{{ $l->discount_amount > 0 ? $money($l->discount_amount) : '—' }}</td>
                    <td class="px-3 py-1.5 text-right text-slate-500">{{ $l->tax_amount != 0 ? $money($l->tax_amount) : '—' }}</td>
                    <td class="px-3 py-1.5 text-right font-medium {{ $l->amount < 0 ? 'text-red-600' : '' }}">{{ $money($l->amount) }}</td>
                </tr>
                @empty
                <tr><td colspan="9" class="px-4 py-8 text-center text-slate-400">No sold items for {{ $label }}.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $lines->links() }}

    @else
    <div class="bg-white rounded-xl border border-slate-200 overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="text-xs text-slate-500 uppercase tracking-wide border-b border-slate-100 bg-slate-50">
                <th class="text-left px-3 py-2">Item</th><th class="text-left px-3 py-2">Warehouse</th>
                <th class="text-right px-3 py-2">Opening</th><th class="text-right px-3 py-2">Stock in</th>
                <th class="text-right px-3 py-2">Stock out</th><th class="text-right px-3 py-2">Closing</th>
            </tr></thead>
            <tbody>
                @forelse ($stock['rows'] as $r)
                <tr class="border-b border-slate-50 hover:bg-slate-50">
                    <td class="px-3 py-1.5"><p class="font-medium">{{ $r['item_name'] }}</p><p class="text-xs text-slate-400">{{ $r['item_code'] }}</p></td>
                    <td class="px-3 py-1.5 text-slate-500">{{ $r['warehouse'] }}</td>
                    <td class="px-3 py-1.5 text-right text-slate-500">{{ $qtyFmt($r['opening']) }}</td>
                    <td class="px-3 py-1.5 text-right text-green-700" title="Returns {{ $qtyFmt($r['returned_in']) }} · other {{ $qtyFmt($r['other_in']) }}">{{ $r['in'] > 0 ? '+'.$qtyFmt($r['in']) : '—' }}</td>
                    <td class="px-3 py-1.5 text-right text-red-600" title="Sold {{ $qtyFmt($r['sold_out']) }} · other {{ $qtyFmt($r['other_out']) }}">{{ $r['out'] > 0 ? '−'.$qtyFmt($r['out']) : '—' }}</td>
                    <td class="px-3 py-1.5 text-right font-semibold">{{ $qtyFmt($r['closing']) }}</td>
                </tr>
                @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-slate-400">No stock recorded for {{ $label }}.</td></tr>
                @endforelse
            </tbody>
            @if ($stock['rows'])
            <tfoot><tr class="bg-slate-50 font-semibold">
                <td class="px-3 py-2" colspan="2">Total</td>
                <td class="px-3 py-2 text-right">{{ $qtyFmt($stock['totals']['opening']) }}</td>
                <td class="px-3 py-2 text-right text-green-700">+{{ $qtyFmt($stock['totals']['in']) }}</td>
                <td class="px-3 py-2 text-right text-red-600">−{{ $qtyFmt($stock['totals']['out']) }}</td>
                <td class="px-3 py-2 text-right">{{ $qtyFmt($stock['totals']['closing']) }}</td>
            </tr></tfoot>
            @endif
        </table>
    </div>
    <p class="text-xs text-slate-400 mt-2">Opening = balance before {{ $from }}; closing = balance at the end of {{ $to }}. Hover Stock in/out for the split (sales, returns, adjustments). The Excel export has these as separate columns.</p>
    @endif
</div>
@endsection
