@extends('layouts.app')
@section('title', 'Daily summary')

@php
    $currency = optional(request()->attributes->get('posProfile'))->effectiveCurrencySymbol() ?? '';
    $precision = 2;
@endphp

@section('content')
<div class="max-w-5xl mx-auto">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-xl font-bold text-slate-800">Daily summary</h1>
        <a href="{{ route('dashboard') }}" class="text-sm text-slate-500 hover:text-slate-700">← Back to dashboard</a>
    </div>
    @include('pos.partials.flash')

    <form method="GET" class="mb-4 flex gap-2">
        <input type="date" name="date" value="{{ $date }}" class="rounded-lg border border-slate-300 px-3 py-2">
        <button class="rounded-lg bg-slate-200 hover:bg-slate-300 px-4 py-2 text-sm">Show</button>
    </form>

    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead><tr class="text-xs text-slate-500 uppercase tracking-wide border-b border-slate-100 bg-slate-50">
                <th class="text-left px-4 py-2">Invoice</th><th class="text-left px-4 py-2">Time</th><th class="text-left px-4 py-2">Customer</th><th class="text-center px-4 py-2">Status</th><th class="text-right px-4 py-2">Total</th>
            </tr></thead>
            <tbody>
                @forelse ($invoices as $inv)
                <tr class="border-b border-slate-50 hover:bg-slate-50">
                    <td class="px-4 py-2 font-mono text-xs"><a href="{{ route('invoices.show', $inv) }}" class="text-brand-600 hover:underline">{{ $inv->invoice_no }}</a></td>
                    <td class="px-4 py-2 text-slate-500">{{ $inv->posting_datetime?->format('H:i') }}</td>
                    <td class="px-4 py-2">{{ $inv->customer?->customer_name ?? '—' }}</td>
                    <td class="px-4 py-2 text-center"><span class="text-xs px-2 py-0.5 rounded-full @if($inv->status==='paid')bg-green-100 text-green-700 @elseif($inv->status==='cancelled')bg-slate-200 text-slate-500 @else bg-amber-100 text-amber-700 @endif">{{ ucfirst(str_replace('_',' ',$inv->status)) }}</span></td>
                    <td class="px-4 py-2 text-right font-medium {{ $inv->is_return ? 'text-red-600' : '' }}">{{ $inv->is_return ? '−' : '' }}{{ number_format($inv->grand_total, $precision) }}</td>
                </tr>
                @empty
                <tr><td colspan="5" class="px-4 py-8 text-center text-slate-400">No invoices on {{ $date }}.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $invoices->links() }}
</div>
@endsection
