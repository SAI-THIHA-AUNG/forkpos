@extends('layouts.app')
@section('title', 'Dashboard')

@php
    $currency = optional(request()->attributes->get('posProfile'))->effectiveCurrencySymbol() ?? '';
    $precision = 2;
@endphp

@section('content')
<div class="max-w-6xl mx-auto">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h1 class="text-xl font-bold text-slate-800">Dashboard</h1>
            <p class="text-sm text-slate-500">{{ now()->format('l, M j, Y') }}</p>
        </div>
        <a href="{{ route('dashboard.daily') }}" class="rounded-lg bg-slate-200 hover:bg-slate-300 text-slate-700 text-sm font-medium px-4 py-2">Daily summary</a>
    </div>
    @include('pos.partials.flash')

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-xs text-slate-500 uppercase tracking-wide">Invoices</p>
            <p class="text-2xl font-bold text-slate-800 mt-1">{{ $sales->invoice_count }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-xs text-slate-500 uppercase tracking-wide">Gross sales</p>
            <p class="text-2xl font-bold text-brand-700 mt-1">{{ $currency }}{{ \App\Support\Money::format($sales->gross_sales, $precision) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-xs text-slate-500 uppercase tracking-wide">Returns</p>
            <p class="text-2xl font-bold text-red-600 mt-1">{{ $currency }}{{ \App\Support\Money::format(abs($sales->returns_total), $precision) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-xs text-slate-500 uppercase tracking-wide">Grand total</p>
            <p class="text-2xl font-bold text-green-700 mt-1">{{ $currency }}{{ \App\Support\Money::format($sales->grand_total, $precision) }}</p>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <h2 class="font-semibold text-slate-700 mb-3">Payment breakdown</h2>
            @if ($paymentBreakdown->isEmpty())
            <p class="text-sm text-slate-400">No sales today yet.</p>
            @else
            <div class="space-y-2">
                @php $max = $paymentBreakdown->max() ?: 1; @endphp
                @foreach ($paymentBreakdown as $mode => $total)
                <div>
                    <div class="flex justify-between text-sm mb-0.5"><span class="text-slate-600">{{ $mode }}</span><span class="font-medium">{{ $currency }}{{ \App\Support\Money::format($total, $precision) }}</span></div>
                    <div class="h-2 rounded bg-slate-100 overflow-hidden"><div class="h-full bg-brand-500" style="width: {{ ($total / $max) * 100 }}%"></div></div>
                </div>
                @endforeach
            </div>
            @endif
        </div>

        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <h2 class="font-semibold text-slate-700 mb-3">Tax &amp; totals</h2>
            <div class="space-y-1 text-sm">
                <div class="flex justify-between"><span class="text-slate-500">Net total</span><span>{{ $currency }}{{ \App\Support\Money::format($sales->net_total, $precision) }}</span></div>
                <div class="flex justify-between"><span class="text-slate-500">Tax total</span><span>{{ $currency }}{{ \App\Support\Money::format($sales->tax_total, $precision) }}</span></div>
                <div class="flex justify-between"><span class="text-slate-500">Discounts given</span><span class="text-red-600">−{{ $currency }}{{ \App\Support\Money::format($sales->discount_total, $precision) }}</span></div>
                <div class="flex justify-between font-bold border-t border-slate-100 pt-1"><span>Grand total</span><span>{{ $currency }}{{ \App\Support\Money::format($sales->grand_total, $precision) }}</span></div>
            </div>
        </div>
    </div>

    <div class="bg-white rounded-xl border border-slate-200">
        <div class="px-4 py-3 border-b border-slate-100"><h2 class="font-semibold text-slate-700">Top 10 items today</h2></div>
        @if ($topItems->isEmpty())
        <p class="px-4 py-6 text-center text-slate-400 text-sm">No items sold today.</p>
        @else
        <table class="w-full text-sm">
            <thead><tr class="text-xs text-slate-500 uppercase tracking-wide border-b border-slate-100">
                <th class="text-left px-4 py-2">Item</th><th class="text-right px-4 py-2">Qty sold</th><th class="text-right px-4 py-2">Revenue</th>
            </tr></thead>
            <tbody>
                @foreach ($topItems as $i)
                <tr class="border-b border-slate-50">
                    <td class="px-4 py-2"><p class="font-medium">{{ $i->item_name }}</p><p class="text-xs text-slate-400">{{ $i->item_code }}</p></td>
                    <td class="text-right px-4 py-2">{{ \App\Support\Money::quantity($i->sold) }}</td>
                    <td class="text-right px-4 py-2 font-medium">{{ $currency }}{{ \App\Support\Money::format($i->revenue, $precision) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endif
    </div>
</div>
@endsection
