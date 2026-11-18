@extends('layouts.app')
@section('title', 'Credit '.$credit->invoice_no)

@section('content')
@php($out = $credit->outstanding)
<div class="max-w-3xl mx-auto">
    <a href="{{ route('credit.index') }}" class="text-sm text-slate-500 hover:underline">← Credit collect</a>
    <div class="flex items-start justify-between mt-2 mb-4">
        <div>
            <h1 class="text-xl font-bold text-slate-800 font-mono">{{ $credit->invoice_no }}</h1>
            <p class="text-sm text-slate-500">{{ $credit->customer?->customer_name }} · van {{ $credit->van_code ?? '—' }} · {{ $credit->posting_date?->format('M j, Y') }}
                @if($credit->due_date) · due {{ $credit->due_date->format('M j, Y') }}@endif</p>
        </div>
        @if ($credit->status === 'open' && $out > 0.009)
        @php($collect = ['id' => $credit->id, 'invoice_no' => $credit->invoice_no, 'outstanding' => $out, 'customer' => $credit->customer?->customer_name])
        <button type="button" class="rounded-lg bg-green-600 hover:bg-green-700 text-white font-semibold px-4 py-2 text-sm"
            onclick='openCollect(@json($collect))'>💵 Collect payment</button>
        @else
        <span class="text-xs px-3 py-1 rounded-full bg-green-100 text-green-700">{{ ucfirst($credit->status) }}</span>
        @endif
    </div>
    @include('pos.partials.flash')

    <div class="grid grid-cols-3 gap-3 mb-4">
        <div class="bg-white rounded-xl border border-slate-200 p-3"><p class="text-xs text-slate-500">Invoice total</p><p class="text-lg font-bold">{{ number_format($credit->grand_total, 2) }}</p></div>
        <div class="bg-white rounded-xl border border-slate-200 p-3"><p class="text-xs text-slate-500">Paid</p><p class="text-lg font-bold text-green-700">{{ number_format($credit->grand_total - $out, 2) }}</p></div>
        <div class="bg-white rounded-xl border border-slate-200 p-3"><p class="text-xs text-slate-500">Outstanding</p><p class="text-lg font-bold text-amber-700">{{ number_format($out, 2) }}</p></div>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden mb-4">
        <table class="w-full text-sm">
            <thead><tr class="text-xs text-slate-500 uppercase border-b border-slate-100 bg-slate-50"><th class="text-left px-4 py-2">Item</th><th class="text-right px-4 py-2">Qty</th><th class="text-right px-4 py-2">Rate</th><th class="text-right px-4 py-2">Amount</th></tr></thead>
            <tbody>
                @forelse ($credit->items ?? [] as $l)
                <tr class="border-b border-slate-50"><td class="px-4 py-2">{{ $l['item_name'] ?? $l['item_code'] }}</td><td class="px-4 py-2 text-right">{{ rtrim(rtrim(number_format($l['qty'], 3), '0'), '.') }} {{ $l['uom'] ?? '' }}</td><td class="px-4 py-2 text-right">{{ number_format($l['rate'], 2) }}</td><td class="px-4 py-2 text-right">{{ number_format($l['amount'], 2) }}</td></tr>
                @empty
                <tr><td colspan="4" class="px-4 py-6 text-center text-slate-400">Item lines are not available yet. Refresh from ERPNext.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-4">
        <h2 class="font-semibold text-slate-700 mb-2">Collections</h2>
        @forelse ($credit->collections as $c)
        <div class="flex items-center justify-between py-1.5 border-t border-slate-100 first:border-0 text-sm">
            <span><span class="font-mono text-xs">{{ $c->collection_no }}</span> · {{ $c->posting_date?->format('M j') }} · {{ $c->mode_of_payment }}@if($c->cashier) · {{ $c->cashier->name }}@endif</span>
            <span class="font-medium">{{ number_format($c->amount, 2) }}
                <span class="ml-1 text-[10px] px-1.5 py-0.5 rounded {{ $c->sync_status === 'synced' ? 'bg-green-100 text-green-700' : ($c->sync_status === 'failed' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700') }}">{{ $c->sync_status === 'synced' ? 'in ERPNext' : ($c->sync_status === 'failed' ? 'rejected' : 'waiting to sync') }}</span></span>
        </div>
        @empty
        <p class="text-sm text-slate-400">Nothing collected from this app yet.</p>
        @endforelse
    </div>
    @if ($credit->invoice)<p class="text-xs text-slate-400 mt-3">Sold here: <a class="underline" href="{{ route('invoices.show', $credit->invoice) }}">view the sale</a></p>@endif
</div>
@include('pos.credit._collect_modal')
@endsection
