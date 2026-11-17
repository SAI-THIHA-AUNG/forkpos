@extends('layouts.app')
@section('title', 'Shifts')

@section('content')
<div class="max-w-6xl mx-auto">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-xl font-bold text-slate-800">Shifts</h1>
        <a href="{{ route('shifts.open.form') }}" class="rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium px-4 py-2">Open shift</a>
    </div>

    @include('pos.partials.flash')

    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <table class="w-full text-sm">
            <thead><tr class="text-xs text-slate-500 uppercase tracking-wide border-b border-slate-100 bg-slate-50">
                <th class="text-left px-4 py-2">Shift</th>
                <th class="text-left px-4 py-2">Profile</th>
                <th class="text-left px-4 py-2">Cashier</th>
                <th class="text-left px-4 py-2">Started</th>
                <th class="text-left px-4 py-2">Closed</th>
                <th class="text-right px-4 py-2">Total</th>
                <th class="text-center px-4 py-2">Status</th>
                <th></th>
            </tr></thead>
            <tbody>
                @forelse ($shifts as $s)
                <tr class="border-b border-slate-50 hover:bg-slate-50">
                    <td class="px-4 py-2 font-mono text-xs">{{ $s->shift_no }}</td>
                    <td class="px-4 py-2">{{ $s->posProfile?->name }}</td>
                    <td class="px-4 py-2">{{ $s->user?->name }}</td>
                    <td class="px-4 py-2 text-slate-500">{{ $s->started_at?->format('M j, H:i') }}</td>
                    <td class="px-4 py-2 text-slate-500">{{ $s->closed_at?->format('M j, H:i') ?? '—' }}</td>
                    <td class="px-4 py-2 text-right font-medium">{{ $s->closingShift ? \App\Support\Money::format($s->closingShift->grand_total, 2) : '—' }}</td>
                    <td class="px-4 py-2 text-center">
                        @if($s->status === 'open')
                        <span class="text-xs px-2 py-0.5 rounded-full bg-green-100 text-green-700">Open</span>
                        @else
                        <span class="text-xs px-2 py-0.5 rounded-full bg-slate-200 text-slate-600">Closed</span>
                        @endif
                    </td>
                    <td class="px-4 py-2 text-right">
                        @if($s->status === 'open')
                        <a href="{{ route('shifts.summary', $s) }}" class="text-brand-600 hover:underline text-xs">Summary / close</a>
                        @else
                        <a href="{{ route('shifts.show', $s) }}" class="text-brand-600 hover:underline text-xs">View</a>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="px-4 py-8 text-center text-slate-400">No shifts yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $shifts->links() }}
</div>
@endsection
