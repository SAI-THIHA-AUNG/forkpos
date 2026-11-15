@extends('layouts.app')
@section('title', 'Sales')

@section('content')
<div class="max-w-6xl mx-auto">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-xl font-bold text-slate-800">Sales history</h1>
        <a href="{{ route('pos.register') }}" class="rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium px-4 py-2">New sale</a>
    </div>
    @include('pos.partials.flash')

    <form method="GET" class="flex gap-2 mb-4">
        <input type="search" name="q" value="{{ $q }}" placeholder="Search invoice no or customer…" class="flex-1 rounded-lg border border-slate-300 px-3 py-2">
        <select name="status" class="rounded-lg border border-slate-300 px-3 py-2 bg-white">
            <option value="">All statuses</option>
            @foreach (['paid','partly_paid','unpaid','returned','cancelled'] as $s)
            <option value="{{ $s }}" @selected($status === $s)>{{ ucfirst(str_replace('_',' ',$s)) }}</option>
            @endforeach
        </select>
        <button class="rounded-lg bg-slate-200 hover:bg-slate-300 px-4 py-2 text-sm">Filter</button>
    </form>

    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead><tr class="text-xs text-slate-500 uppercase tracking-wide border-b border-slate-100 bg-slate-50">
                <th class="text-left px-4 py-2">Invoice</th><th class="text-left px-4 py-2">Date</th><th class="text-left px-4 py-2">Customer</th><th class="text-left px-4 py-2">Cashier</th><th class="text-center px-4 py-2">Status</th><th class="text-right px-4 py-2">Total</th><th class="text-center px-4 py-2">Sync</th><th></th>
            </tr></thead>
            <tbody>
                @forelse ($invoices as $inv)
                <tr class="border-b border-slate-50 hover:bg-slate-50">
                    <td class="px-4 py-2 font-mono text-xs"><a href="{{ route('invoices.show', $inv) }}" class="text-brand-600 hover:underline">{{ $inv->invoice_no }}</a></td>
                    <td class="px-4 py-2 text-slate-500">{{ $inv->posting_datetime?->format('M j, H:i') }}</td>
                    <td class="px-4 py-2">{{ $inv->customer?->customer_name ?? '—' }}</td>
                    <td class="px-4 py-2 text-slate-500">{{ $inv->cashier?->name ?? '—' }}</td>
                    <td class="px-4 py-2 text-center"><span class="text-xs px-2 py-0.5 rounded-full @if($inv->status==='paid')bg-green-100 text-green-700 @elseif($inv->status==='cancelled')bg-slate-200 text-slate-500 @else bg-amber-100 text-amber-700 @endif">{{ ucfirst(str_replace('_',' ',$inv->status)) }}</span></td>
                    <td class="px-4 py-2 text-right font-medium {{ $inv->is_return ? 'text-red-600' : '' }}">{{ $inv->is_return ? '−' : '' }}{{ number_format($inv->grand_total, 2) }}</td>
                    <td class="px-4 py-2 text-center">
                        @if($inv->sync_status === 'synced')<span class="text-xs text-green-600">✓</span>
                        @elseif($inv->sync_status === 'failed')<span class="text-xs text-red-500" title="{{ $inv->erpnext_id }}">!</span>
                        @else<span class="text-xs text-slate-300">·</span>@endif
                    </td>
                    <td class="px-4 py-2 text-right"><a href="{{ route('invoices.show', $inv) }}" class="text-brand-600 hover:underline text-xs">View</a></td>
                </tr>
                @empty
                <tr><td colspan="8" class="px-4 py-8 text-center text-slate-400">No invoices found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $invoices->links() }}
</div>
@endsection
