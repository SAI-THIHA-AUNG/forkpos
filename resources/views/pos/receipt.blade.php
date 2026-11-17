@extends('layouts.app')
@section('title', "Receipt {$invoice->invoice_no}")

@php
    $currency = \App\Models\PosProfile::symbolFor($invoice->currency);
    $precision = $setting->currency_precision ?? 2;
@endphp

@section('content')
<div class="max-w-md mx-auto py-6">
    <div class="no-print flex justify-between mb-4">
        <a href="{{ route('pos.register') }}" class="text-sm text-slate-500 hover:text-slate-700">← New sale</a>
        <button onclick="window.print()" class="rounded-lg bg-slate-700 hover:bg-slate-800 text-white text-sm font-medium px-3 py-1.5">Print</button>
    </div>

    @include('pos.partials.flash')

    <div class="print-area bg-white rounded-xl border border-slate-200 p-6 shadow-sm receipt-paper" id="receipt">
        <div class="text-center mb-4">
            @if ($setting->receipt_header)
            <p class="text-sm font-semibold text-slate-800 whitespace-pre-line">{{ $setting->receipt_header }}</p>
            @else
            <p class="text-lg font-bold text-slate-800">{{ $setting->company_name }}</p>
            @endif
            <p class="text-xs text-slate-500 mt-1">{{ $invoice->posProfile?->name ?? '' }}</p>
        </div>

        <div class="border-t border-dashed border-slate-300 pt-3 mb-3 text-xs text-slate-600 space-y-0.5">
            <div class="flex justify-between"><span>Invoice</span><span class="font-mono font-semibold">{{ $invoice->invoice_no }}</span></div>
            <div class="flex justify-between"><span>Date</span><span>{{ $invoice->posting_datetime?->format('Y-m-d H:i') }}</span></div>
            <div class="flex justify-between"><span>Cashier</span><span>{{ $invoice->cashier?->name }}</span></div>
            @if($invoice->shift_id)<div class="flex justify-between"><span>Shift</span><span>#{{ $invoice->shift_id }}</span></div>@endif
            <div class="flex justify-between"><span>Customer</span><span>{{ $invoice->customer?->customer_name ?? 'Walk-in' }}</span></div>
        </div>

        <table class="w-full text-xs mb-3">
            <thead>
                <tr class="border-b border-slate-300 text-slate-500">
                    <th class="text-left pb-1">Item</th>
                    <th class="text-center pb-1">Qty</th>
                    <th class="text-right pb-1">Price</th>
                    <th class="text-right pb-1">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($invoice->items as $line)
                <tr class="border-b border-slate-50">
                    <td class="py-1">
                        <p>{{ $line->item_name }}</p>
                        <p class="text-[10px] text-slate-400">{{ $line->item_code }} · {{ $line->uom }}
                        @if($line->discount_amount > 0) · disc {{ $currency }}{{ \App\Support\Money::format($line->discount_amount, $precision) }}@endif</p>
                    </td>
                    <td class="text-center py-1">{{ \App\Support\Money::quantity($line->qty) }}</td>
                    <td class="text-right py-1">{{ \App\Support\Money::format($line->rate, $precision) }}</td>
                    <td class="text-right py-1 font-medium">{{ \App\Support\Money::format($line->amount, $precision) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>

        <div class="border-t border-dashed border-slate-300 pt-3 text-sm space-y-1">
            @php
                $lineDiscounts = max(0, $invoice->discount_total - $invoice->additional_discount_amount);
                $linesBeforeDiscount = $invoice->items->sum('amount') + $lineDiscounts;
            @endphp
            <div class="flex justify-between"><span class="text-slate-500">Subtotal</span><span>{{ $currency }}{{ \App\Support\Money::format($linesBeforeDiscount, $precision) }}</span></div>
            @if ($lineDiscounts > 0)
            <div class="flex justify-between"><span class="text-slate-500">Line discounts</span><span class="text-red-600">−{{ $currency }}{{ \App\Support\Money::format($lineDiscounts, $precision) }}</span></div>
            @endif
            @if ($invoice->additional_discount_amount > 0)
            <div class="flex justify-between"><span class="text-slate-500">Total discount</span><span class="text-red-600">−{{ $currency }}{{ \App\Support\Money::format($invoice->additional_discount_amount, $precision) }}</span></div>
            @endif
            @if ($invoice->coupon_discount > 0)
            <div class="flex justify-between"><span class="text-slate-500">Coupon ({{ $invoice->coupon_code }})</span><span class="text-red-600">−{{ $currency }}{{ \App\Support\Money::format($invoice->coupon_discount, $precision) }}</span></div>
            @endif
            @if ($invoice->loyalty_points_spent > 0)
            <div class="flex justify-between"><span class="text-slate-500">Loyalty ({{ $invoice->loyalty_points_spent }} pts)</span><span class="text-red-600">−{{ $currency }}{{ \App\Support\Money::format($invoice->loyalty_points_spent, $precision) }}</span></div>
            @endif
            @foreach ($invoice->taxes as $t)
            <div class="flex justify-between"><span class="text-slate-500">{{ $t->description }} ({{ \App\Support\Money::format($t->rate, 2) }}%) {{ $t->included_in_price ? '(incl.)' : '' }}</span><span>{{ $currency }}{{ \App\Support\Money::format($t->amount, $precision) }}</span></div>
            @endforeach
            <div class="flex justify-between font-bold text-base border-t border-slate-300 pt-2"><span>Total</span><span>{{ $currency }}{{ \App\Support\Money::format($invoice->grand_total, $precision) }}</span></div>
        </div>

        <div class="border-t border-dashed border-slate-300 mt-3 pt-3 text-sm space-y-1">
            @foreach ($invoice->payments as $p)
            <div class="flex justify-between"><span class="text-slate-500">{{ $p->mode_of_payment }}</span><span>{{ $currency }}{{ \App\Support\Money::format($p->amount, $precision) }}</span></div>
            @endforeach
            @if ($invoice->change_amount > 0)
            <div class="flex justify-between"><span class="text-slate-500">Change</span><span>{{ $currency }}{{ \App\Support\Money::format($invoice->change_amount, $precision) }}</span></div>
            @endif
            @if ($invoice->outstanding_amount > 0.001)
            <div class="flex justify-between text-red-600 font-semibold"><span>Outstanding</span><span>{{ $currency }}{{ \App\Support\Money::format($invoice->outstanding_amount, $precision) }}</span></div>
            @endif
        </div>

        @if ($invoice->loyalty_points_earned > 0)
        <p class="text-center text-xs text-slate-500 mt-3">+{{ $invoice->loyalty_points_earned }} loyalty points earned</p>
        @endif

        @if ($setting->receipt_footer)
        <p class="text-center text-xs text-slate-500 mt-4 whitespace-pre-line">{{ $setting->receipt_footer }}</p>
        @endif
    </div>
</div>

<style>
    @@media print {
        @@page { size: 80mm auto; margin: 4mm; }
        body { background: white; }
        .receipt-paper { border: none; box-shadow: none; padding: 0; }
    }
</style>
@endsection
