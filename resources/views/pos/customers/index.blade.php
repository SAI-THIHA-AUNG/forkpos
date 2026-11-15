@extends('layouts.app')
@section('title', 'Customers')

@section('content')
<div class="max-w-6xl mx-auto">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-xl font-bold text-slate-800">Customers</h1>
        <a href="{{ route('customers.create') }}" class="rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium px-4 py-2">+ New customer</a>
    </div>
    @include('pos.partials.flash')

    <form method="GET" class="mb-4">
        <input type="search" name="q" value="{{ $q }}" placeholder="Search name, code, phone…" class="w-full rounded-lg border border-slate-300 px-3 py-2">
    </form>

    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead><tr class="text-xs text-slate-500 uppercase tracking-wide border-b border-slate-100 bg-slate-50">
                <th class="text-left px-4 py-2">Name</th><th class="text-left px-4 py-2">Group</th><th class="text-left px-4 py-2">Territory</th><th class="text-left px-4 py-2">Phone</th><th class="text-right px-4 py-2">Loyalty</th><th></th>
            </tr></thead>
            <tbody>
                @forelse ($customers as $c)
                <tr class="border-b border-slate-50 hover:bg-slate-50">
                    <td class="px-4 py-2 font-medium">{{ $c->customer_name }} @if($c->is_walk_in)<span class="text-[10px] px-1 rounded bg-slate-100 text-slate-500">walk-in</span>@endif</td>
                    <td class="px-4 py-2 text-slate-500">{{ $c->group?->name ?? '—' }}</td>
                    <td class="px-4 py-2 text-slate-500">{{ $c->territory?->name ?? '—' }}</td>
                    <td class="px-4 py-2 text-slate-500">{{ $c->phone ?? '—' }}</td>
                    <td class="px-4 py-2 text-right text-slate-600">{{ $c->loyalty_points }}</td>
                    <td class="px-4 py-2 text-right"><a href="{{ route('customers.show', $c) }}" class="text-brand-600 hover:underline text-xs">View</a></td>
                </tr>
                @empty
                <tr><td colspan="6" class="px-4 py-8 text-center text-slate-400">No customers found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $customers->links() }}
</div>
@endsection
