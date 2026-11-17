@extends('layouts.app')
@section('title', $customer->customer_name)

@section('content')
<div class="max-w-4xl mx-auto">
    <a href="{{ route('customers.index') }}" class="inline-block text-sm text-slate-500 hover:text-slate-700 mb-3">&larr; All customers</a>
    <div class="flex items-center justify-between mb-4">
        <div>
            <h1 class="text-xl font-bold text-slate-800">{{ $customer->customer_name }}</h1>
            <p class="text-sm text-slate-500">{{ $customer->customer_code }} · {{ $customer->group?->name ?? 'No group' }} @if($customer->is_walk_in)<span class="text-[10px] px-1 rounded bg-slate-100 text-slate-500">walk-in</span>@endif</p>
        </div>
        <a href="{{ route('customers.edit', $customer) }}" class="rounded-lg bg-slate-200 hover:bg-slate-300 text-slate-700 text-sm font-medium px-4 py-2">Edit</a>
    </div>
    @include('pos.partials.flash')

    <div class="grid grid-cols-3 gap-3 mb-6">
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-xs text-slate-500 uppercase tracking-wide">Total spent</p>
            <p class="text-2xl font-bold text-brand-700 mt-1">{{ \App\Support\Money::format($totalSpent, 2) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-xs text-slate-500 uppercase tracking-wide">Invoices</p>
            <p class="text-2xl font-bold text-slate-800 mt-1">{{ $invoices->count() }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-xs text-slate-500 uppercase tracking-wide">Loyalty</p>
            <p class="text-2xl font-bold text-amber-600 mt-1">{{ $customer->loyalty_points }} pts</p>
        </div>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-4 mb-6 text-sm">
        <div class="grid grid-cols-2 gap-2">
            <p><span class="text-slate-500">Phone:</span> {{ $customer->phone ?? '—' }}</p>
            <p><span class="text-slate-500">Email:</span> {{ $customer->email ?? '—' }}</p>
            <p><span class="text-slate-500">Address:</span> {{ $customer->address_line1 ?? '—' }}</p>
            <p><span class="text-slate-500">City:</span> {{ $customer->city ?? '—' }}</p>
        </div>
    </div>

    <div class="bg-white rounded-xl border border-slate-200">
        <div class="px-4 py-3 border-b border-slate-100"><h2 class="font-semibold text-slate-700">Recent invoices</h2></div>
        <table class="w-full text-sm">
            <thead><tr class="text-xs text-slate-500 uppercase tracking-wide border-b border-slate-100">
                <th class="text-left px-4 py-2">Invoice</th><th class="text-left px-4 py-2">Date</th><th class="text-left px-4 py-2">Status</th><th class="text-right px-4 py-2">Total</th>
            </tr></thead>
            <tbody>
                @forelse ($invoices as $inv)
                <tr class="border-b border-slate-50 hover:bg-slate-50">
                    <td class="px-4 py-2 font-mono text-xs"><a href="{{ route('invoices.show', $inv) }}" class="text-brand-600 hover:underline">{{ $inv->invoice_no }}</a></td>
                    <td class="px-4 py-2 text-slate-500">{{ $inv->posting_datetime?->format('M j, Y H:i') }}</td>
                    <td class="px-4 py-2"><span class="text-xs px-2 py-0.5 rounded-full @if($inv->status==='paid')bg-green-100 text-green-700 @elseif($inv->status==='cancelled')bg-slate-200 text-slate-500 @else bg-amber-100 text-amber-700 @endif">{{ ucfirst(str_replace('_',' ',$inv->status)) }}</span></td>
                    <td class="px-4 py-2 text-right font-medium">{{ \App\Support\Money::format($inv->grand_total, 2) }}</td>
                </tr>
                @empty
                <tr><td colspan="4" class="px-4 py-6 text-center text-slate-400 text-sm">No invoices yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
