@extends('layouts.app')
@section('title', 'ERPNext Sync')

@section('content')
<div class="max-w-5xl mx-auto">
    <h1 class="text-xl font-bold text-slate-800 mb-4">ERPNext sync</h1>
    @include('pos.partials.flash')

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-xs text-slate-500 uppercase tracking-wide">Connector</p>
            <p class="font-semibold mt-1 @if($configured)text-green-700 @else text-red-600 @endif">{{ $configured ? 'Configured' : 'Not configured' }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-xs text-slate-500 uppercase tracking-wide">Reachable</p>
            <p class="font-semibold mt-1 @if($reachable === true)text-green-700 @elseif($reachable === false)text-red-600 @else text-slate-500 @endif">{{ $reachable === true ? 'Yes' : ($reachable === false ? 'No' : 'Unknown') }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-xs text-slate-500 uppercase tracking-wide">Pending</p>
            <p class="text-2xl font-bold text-amber-600 mt-1">{{ $pendingCount }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-xs text-slate-500 uppercase tracking-wide">Failed</p>
            <p class="text-2xl font-bold text-red-600 mt-1">{{ $failedCount }}</p>
        </div>
    </div>

    @if ($ping)
    <p class="text-xs text-slate-500 mb-4">ERPNext ping: <code>{{ $ping }}</code></p>
    @endif

    <div class="bg-white rounded-xl border border-slate-200 p-4 mb-6">
        <h2 class="font-semibold text-slate-700 mb-3">Pull masters from ERPNext</h2>
        <p class="text-sm text-slate-500 mb-3">Fetches UOMs, item groups, warehouses, taxes, items, prices, customers and stock into the local SQLite database. Idempotent — safe to re-run.</p>
        <form method="POST" action="{{ route('sync.masters') }}" class="flex items-end gap-3">
            @csrf
            <div class="flex-1">
                <label class="block text-xs text-slate-500 mb-1">Entities (comma-separated, empty = all)</label>
                <input type="text" name="entities[]" placeholder="uom,item,item_price,stock" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
            </div>
            <div>
                <label class="block text-xs text-slate-500 mb-1">Limit</label>
                <input type="number" name="limit" value="500" min="1" max="2000" class="w-24 rounded border border-slate-300 px-2 py-1.5 text-sm no-spin">
            </div>
            <button class="rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium px-4 py-1.5">Pull masters</button>
        </form>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-4 mb-6">
        <h2 class="font-semibold text-slate-700 mb-3">Push pending invoices</h2>
        <p class="text-sm text-slate-500 mb-3">Submits all pending invoices to ERPNext as POS Invoice documents. Successful pushes are marked synced; failures are flagged for retry.</p>
        <form method="POST" action="{{ route('sync.push') }}" class="flex items-end gap-3">
            @csrf
            <div class="flex-1">
                <label class="block text-xs text-slate-500 mb-1">Invoice IDs (optional, empty = all pending)</label>
                <input type="text" name="invoice_ids[]" placeholder="e.g. 1,2,3" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
            </div>
            <button class="rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium px-4 py-1.5">Push invoices</button>
        </form>
    </div>

    <div class="bg-white rounded-xl border border-slate-200">
        <div class="px-4 py-3 border-b border-slate-100"><h2 class="font-semibold text-slate-700">Recent pending / failed invoices</h2></div>
        @if ($recent->isEmpty())
        <p class="px-4 py-6 text-center text-slate-400 text-sm">No pending invoices — all synced.</p>
        @else
        <table class="w-full text-sm">
            <thead><tr class="text-xs text-slate-500 uppercase tracking-wide border-b border-slate-100">
                <th class="text-left px-4 py-2">Invoice</th><th class="text-left px-4 py-2">Status</th><th class="text-left px-4 py-2">Sync</th><th class="text-left px-4 py-2">ERPNext id</th>
            </tr></thead>
            <tbody>
                @foreach ($recent as $inv)
                <tr class="border-b border-slate-50">
                    <td class="px-4 py-2 font-mono text-xs"><a href="{{ route('invoices.show', $inv) }}" class="text-brand-600 hover:underline">{{ $inv->invoice_no }}</a></td>
                    <td class="px-4 py-2 text-slate-500">{{ $inv->status }}</td>
                    <td class="px-4 py-2"><span class="text-xs px-2 py-0.5 rounded @if($inv->sync_status==='synced')bg-green-100 text-green-700 @elseif($inv->sync_status==='failed')bg-red-100 text-red-700 @else bg-amber-100 text-amber-700 @endif">{{ ucfirst($inv->sync_status) }}</span></td>
                    <td class="px-4 py-2 text-slate-400 text-xs">{{ $inv->erpnext_id ?? '—' }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        @endif
    </div>
</div>
@endsection
