@extends('layouts.app')
@section('title', 'Credit collect')

@section('content')
<div class="max-w-6xl mx-auto">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h1 class="text-xl font-bold text-slate-800">Credit collect</h1>
            <p class="text-sm text-slate-500">Unpaid credit invoices from every van. Collect on any of them, whichever van made the sale.</p>
        </div>
        <form method="POST" action="{{ route('credit.refresh') }}">@csrf
            <button class="rounded-lg bg-slate-800 hover:bg-slate-900 text-white text-sm font-medium px-4 py-2" title="Pull the latest unpaid invoices from ERPNext">↻ Refresh from ERPNext</button>
        </form>
    </div>
    @include('pos.partials.flash')

    @if ($failed->isNotEmpty())
    <div class="mb-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm">
        <p class="font-semibold text-red-700">Collections ERPNext rejected</p>
        <p class="text-red-600 text-xs mb-2">Usually another van already collected the invoice. Check, then discard to free the balance.</p>
        @foreach ($failed as $f)
        <div class="flex items-center justify-between gap-3 py-1 border-t border-red-100">
            <span><span class="font-mono text-xs">{{ $f->collection_no }}</span> · {{ $f->customer?->customer_name }} · {{ number_format($f->amount, 2) }} on {{ $f->creditInvoice?->invoice_no }}<br><span class="text-xs text-red-500">{{ $f->sync_error }}</span></span>
            <form method="POST" action="{{ route('credit.discard', $f) }}" onsubmit="return confirm('Discard this collection?')">@csrf<button class="text-xs text-red-700 underline">Discard</button></form>
        </div>
        @endforeach
    </div>
    @endif

    <form method="GET" class="flex gap-2 mb-4">
        <input type="search" name="q" value="{{ $q }}" placeholder="Search invoice no or customer…" class="flex-1 rounded-lg border border-slate-300 px-3 py-2">
        <select name="van" class="rounded-lg border border-slate-300 px-3 py-2 bg-white">
            <option value="">All vans</option>
            @foreach ($vans as $v)<option value="{{ $v }}" @selected($van === $v)>{{ $v }}</option>@endforeach
        </select>
        <button class="rounded-lg bg-slate-200 hover:bg-slate-300 px-4 py-2 text-sm">Filter</button>
    </form>

    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead><tr class="text-xs text-slate-500 uppercase tracking-wide border-b border-slate-100 bg-slate-50">
                <th class="text-left px-4 py-2">Invoice</th><th class="text-left px-4 py-2">Customer</th><th class="text-center px-4 py-2">Van</th><th class="text-left px-4 py-2">Date</th><th class="text-left px-4 py-2">Due</th><th class="text-right px-4 py-2">Total</th><th class="text-right px-4 py-2">Outstanding</th><th></th>
            </tr></thead>
            <tbody>
                @forelse ($invoices as $c)
                <tr class="border-b border-slate-50 hover:bg-slate-50">
                    <td class="px-4 py-2 font-mono text-xs"><a href="{{ route('credit.show', $c) }}" class="text-brand-600 hover:underline">{{ $c->invoice_no }}</a></td>
                    <td class="px-4 py-2">{{ $c->customer?->customer_name ?? '—' }}</td>
                    <td class="px-4 py-2 text-center text-xs text-slate-500">{{ $c->van_code ?? '—' }}</td>
                    <td class="px-4 py-2 text-slate-500">{{ $c->posting_date?->format('M j') }}</td>
                    <td class="px-4 py-2 {{ $c->isOverdue() ? 'text-red-600 font-medium' : 'text-slate-500' }}">{{ $c->due_date?->format('M j') ?? '—' }}@if($c->isOverdue()) <span class="text-[10px] uppercase">overdue</span>@endif</td>
                    <td class="px-4 py-2 text-right">{{ number_format($c->grand_total, 2) }}</td>
                    <td class="px-4 py-2 text-right font-semibold text-amber-700">{{ number_format($c->outstanding, 2) }}</td>
                    <td class="px-4 py-2 text-right whitespace-nowrap">
                        @php($collect = ['id' => $c->id, 'invoice_no' => $c->invoice_no, 'outstanding' => $c->outstanding, 'customer' => $c->customer?->customer_name])
                        <button type="button" title="Quick collect" class="inline-flex items-center justify-center w-8 h-8 rounded-full bg-green-100 hover:bg-green-200 text-green-700"
                            onclick='openCollect(@json($collect))'>💵</button>
                        <a href="{{ route('credit.show', $c) }}" class="ml-2 text-brand-600 hover:underline text-xs">Detail</a>
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="px-4 py-8 text-center text-slate-400">No unpaid credit invoices. Use “Refresh from ERPNext” to check for new ones.</td></tr>
                @endforelse
            </tbody>
            @if ($invoices->isNotEmpty())
            <tfoot><tr class="bg-slate-50 font-semibold"><td colspan="6" class="px-4 py-2 text-right">Total outstanding</td><td class="px-4 py-2 text-right text-amber-700">{{ number_format($total, 2) }}</td><td></td></tr></tfoot>
            @endif
        </table>
    </div>
</div>
@include('pos.credit._collect_modal')
@endsection
