@extends('layouts.app')
@section('title', "Invoice {$invoice->invoice_no}")

@php
    $currency = $invoice->currency ?? '$';
    $precision = 2;
@endphp

@section('content')
<div class="max-w-3xl mx-auto">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h1 class="text-xl font-bold text-slate-800">{{ $invoice->invoice_no }}</h1>
            <p class="text-sm text-slate-500">{{ $invoice->posting_datetime?->format('M j, Y H:i') }} · {{ $invoice->cashier?->name }} · {{ $invoice->posProfile?->name }}</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('pos.receipt', $invoice) }}" class="rounded-lg bg-slate-200 hover:bg-slate-300 text-slate-700 text-sm font-medium px-3 py-2">Receipt</a>
            @if ($invoice->status !== 'cancelled' && ! $invoice->is_return)
            <a href="{{ route('invoices.return.form', $invoice) }}" class="rounded-lg bg-amber-500 hover:bg-amber-600 text-white text-sm font-medium px-3 py-2">Return</a>
            @endif
        </div>
    </div>
    @include('pos.partials.flash')

    <div class="grid grid-cols-3 gap-3 mb-4">
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-xs text-slate-500 uppercase tracking-wide">Status</p>
            <p class="font-semibold mt-1 @if($invoice->status==='paid')text-green-700 @elseif($invoice->status==='cancelled')text-slate-500 @else text-amber-700 @endif">{{ ucfirst(str_replace('_',' ',$invoice->status)) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-xs text-slate-500 uppercase tracking-wide">Customer</p>
            <p class="font-semibold mt-1">{{ $invoice->customer?->customer_name ?? 'Walk-in' }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-xs text-slate-500 uppercase tracking-wide">Total</p>
            <p class="font-semibold mt-1 text-brand-700">{{ $currency }}{{ number_format($invoice->grand_total, $precision) }}</p>
        </div>
    </div>

    @if ($invoice->is_return && $invoice->return_against_id)
    <div class="mb-4 p-3 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-sm">
        This is a return against invoice <a href="{{ route('invoices.show', $invoice->return_against_id) }}" class="underline font-medium">#{{ $invoice->return_against_id }}</a>.
    </div>
    @endif

    <div class="bg-white rounded-xl border border-slate-200 mb-4">
        <div class="px-4 py-3 border-b border-slate-100"><h2 class="font-semibold text-slate-700">Items</h2></div>
        <table class="w-full text-sm">
            <thead><tr class="text-xs text-slate-500 uppercase tracking-wide border-b border-slate-100">
                <th class="text-left px-4 py-2">Item</th><th class="text-center px-4 py-2">Qty</th><th class="text-right px-4 py-2">Rate</th><th class="text-right px-4 py-2">Disc</th><th class="text-right px-4 py-2">Tax</th><th class="text-right px-4 py-2">Amount</th>
            </tr></thead>
            <tbody>
                @foreach ($invoice->items as $line)
                <tr class="border-b border-slate-50">
                    <td class="px-4 py-2"><p class="font-medium">{{ $line->item_name }}</p><p class="text-xs text-slate-400">{{ $line->item_code }} · {{ $line->uom }}</p></td>
                    <td class="text-center px-4 py-2">{{ number_format($line->qty, $precision) }}</td>
                    <td class="text-right px-4 py-2">{{ number_format($line->rate, $precision) }}</td>
                    <td class="text-right px-4 py-2 text-slate-500">{{ $line->discount_amount > 0 ? number_format($line->discount_amount, $precision) : '—' }}</td>
                    <td class="text-right px-4 py-2 text-slate-500">{{ $line->tax_amount > 0 ? number_format($line->tax_amount, $precision) : '—' }}</td>
                    <td class="text-right px-4 py-2 font-medium">{{ number_format($line->amount, $precision) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 mb-4 p-4">
        <div class="grid grid-cols-2 gap-x-8 gap-y-1 text-sm">
            <div class="flex justify-between"><span class="text-slate-500">Subtotal</span><span>{{ $currency }}{{ number_format($invoice->net_total + $invoice->discount_total, $precision) }}</span></div>
            @if ($invoice->discount_total > 0)<div class="flex justify-between"><span class="text-slate-500">Discounts</span><span class="text-red-600">−{{ $currency }}{{ number_format($invoice->discount_total, $precision) }}</span></div>@endif
            @if ($invoice->coupon_discount > 0)<div class="flex justify-between"><span class="text-slate-500">Coupon ({{ $invoice->coupon_code }})</span><span class="text-red-600">−{{ $currency }}{{ number_format($invoice->coupon_discount, $precision) }}</span></div>@endif
            <div class="flex justify-between"><span class="text-slate-500">Tax</span><span>{{ $currency }}{{ number_format($invoice->tax_total, $precision) }}</span></div>
            <div class="flex justify-between font-bold border-t border-slate-100 pt-1"><span>Total</span><span>{{ $currency }}{{ number_format($invoice->grand_total, $precision) }}</span></div>
            <div class="flex justify-between"><span class="text-slate-500">Paid</span><span>{{ $currency }}{{ number_format($invoice->paid_amount, $precision) }}</span></div>
            @if ($invoice->change_amount > 0)<div class="flex justify-between"><span class="text-slate-500">Change</span><span>{{ $currency }}{{ number_format($invoice->change_amount, $precision) }}</span></div>@endif
            @if ($invoice->outstanding_amount > 0.001)<div class="flex justify-between text-red-600 font-semibold"><span>Outstanding</span><span>{{ $currency }}{{ number_format($invoice->outstanding_amount, $precision) }}</span></div>@endif
        </div>
        @if ($invoice->payments->isNotEmpty())
        <div class="mt-3 pt-3 border-t border-slate-100">
            <p class="text-xs text-slate-500 uppercase tracking-wide mb-1">Payments</p>
            <div class="space-y-1 text-sm">
                @foreach ($invoice->payments as $p)
                <div class="flex justify-between"><span class="text-slate-600">{{ $p->mode_of_payment }} {{ $p->reference_no ? "({$p->reference_no})" : '' }}</span><span>{{ $currency }}{{ number_format($p->amount, $precision) }}</span></div>
                @endforeach
            </div>
        </div>
        @endif
    </div>

    @if ($invoice->status !== 'cancelled')
    <div class="bg-white rounded-xl border border-slate-200 p-4">
        <form method="POST" action="{{ route('invoices.cancel', $invoice) }}" onsubmit="return confirm('Cancel this invoice? Stock will be restored.')">
            @csrf
            <div class="flex items-end gap-2">
                <div class="flex-1">
                    <label class="block text-xs text-slate-500 mb-1">Cancel reason (optional)</label>
                    <input type="text" name="reason" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                </div>
                <button class="rounded-lg bg-red-600 hover:bg-red-700 text-white text-sm font-medium px-3 py-1.5">Cancel invoice</button>
            </div>
        </form>
    </div>
    @endif

    @if ($invoice->remarks)
    <p class="text-xs text-slate-400 mt-4">Remarks: {{ $invoice->remarks }}</p>
    @endif
</div>
@endsection
