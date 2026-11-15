@extends('layouts.app')
@section('title', "Shift {$shift->shift_no}")

@section('content')
<div class="max-w-5xl mx-auto">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-xl font-bold text-slate-800">Shift {{ $shift->shift_no }}</h1>
        <a href="{{ route('shifts.index') }}" class="text-sm text-slate-500 hover:text-slate-700">← All shifts</a>
    </div>

    @include('pos.partials.flash')

    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-xs text-slate-500 uppercase tracking-wide">Status</p>
            <p class="font-semibold mt-1 @if($shift->status==='open')text-green-700 @else text-slate-600 @endif">{{ ucfirst($shift->status) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-xs text-slate-500 uppercase tracking-wide">Profile</p>
            <p class="font-semibold mt-1">{{ $shift->posProfile?->name }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-xs text-slate-500 uppercase tracking-wide">Started</p>
            <p class="font-semibold mt-1">{{ $shift->started_at?->format('M j, Y H:i') }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-xs text-slate-500 uppercase tracking-wide">Closed</p>
            <p class="font-semibold mt-1">{{ $shift->closed_at?->format('M j, Y H:i') ?? '—' }}</p>
        </div>
    </div>

    @if ($shift->closingShift)
    <div class="bg-white rounded-xl border border-slate-200 mb-6">
        <div class="px-4 py-3 border-b border-slate-100"><h2 class="font-semibold text-slate-700">Reconciliation — {{ $shift->closingShift->closing_no }}</h2></div>
        <table class="w-full text-sm">
            <thead><tr class="text-xs text-slate-500 uppercase tracking-wide border-b border-slate-100">
                <th class="text-left px-4 py-2">Mode</th>
                <th class="text-right px-4 py-2">Opening</th>
                <th class="text-right px-4 py-2">Collected</th>
                <th class="text-right px-4 py-2">Cash In</th>
                <th class="text-right px-4 py-2">Cash Out</th>
                <th class="text-right px-4 py-2">Expected</th>
                <th class="text-right px-4 py-2">Counted</th>
                <th class="text-right px-4 py-2">Diff</th>
            </tr></thead>
            <tbody>
                @foreach ($shift->closingShift->payments as $p)
                <tr class="border-b border-slate-50">
                    <td class="px-4 py-2 font-medium">{{ $p->mode_of_payment }}</td>
                    <td class="text-right px-4 py-2">{{ number_format($p->opening_amount, 2) }}</td>
                    <td class="text-right px-4 py-2">{{ number_format($p->collected_amount, 2) }}</td>
                    <td class="text-right px-4 py-2 text-green-600">+{{ number_format($p->cash_in_amount, 2) }}</td>
                    <td class="text-right px-4 py-2 text-red-600">−{{ number_format($p->cash_out_amount, 2) }}</td>
                    <td class="text-right px-4 py-2 font-semibold">{{ number_format($p->expected_amount, 2) }}</td>
                    <td class="text-right px-4 py-2">{{ number_format($p->counted_amount, 2) }}</td>
                    <td class="text-right px-4 py-2 font-medium {{ $p->difference > 0.001 ? 'text-green-600' : ($p->difference < -0.001 ? 'text-red-600' : 'text-slate-500') }}">{{ $p->difference >= 0 ? '+' : '' }}{{ number_format($p->difference, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if ($shift->closingShift->taxes->isNotEmpty())
    <div class="bg-white rounded-xl border border-slate-200 mb-6">
        <div class="px-4 py-3 border-b border-slate-100"><h2 class="font-semibold text-slate-700">Tax breakdown</h2></div>
        <table class="w-full text-sm">
            <thead><tr class="text-xs text-slate-500 uppercase tracking-wide border-b border-slate-100">
                <th class="text-left px-4 py-2">Description</th><th class="text-right px-4 py-2">Rate</th><th class="text-right px-4 py-2">Amount</th>
            </tr></thead>
            <tbody>
                @foreach ($shift->closingShift->taxes as $t)
                <tr class="border-b border-slate-50">
                    <td class="px-4 py-2">{{ $t->description }}</td>
                    <td class="text-right px-4 py-2">{{ number_format($t->rate, 2) }}%</td>
                    <td class="text-right px-4 py-2 font-medium">{{ number_format($t->amount, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
    @endif

    @if ($shift->cashMovements->isNotEmpty())
    <div class="bg-white rounded-xl border border-slate-200 mb-6">
        <div class="px-4 py-3 border-b border-slate-100"><h2 class="font-semibold text-slate-700">Cash movements ({{ $shift->cashMovements->count() }})</h2></div>
        <table class="w-full text-sm">
            <thead><tr class="text-xs text-slate-500 uppercase tracking-wide border-b border-slate-100">
                <th class="text-left px-4 py-2">When</th><th class="text-left px-4 py-2">Type</th><th class="text-left px-4 py-2">Mode</th><th class="text-left px-4 py-2">Reason</th><th class="text-right px-4 py-2">Amount</th>
            </tr></thead>
            <tbody>
                @foreach ($shift->cashMovements as $m)
                <tr class="border-b border-slate-50">
                    <td class="px-4 py-2 text-slate-500">{{ $m->created_at?->format('M j, H:i') }}</td>
                    <td class="px-4 py-2"><span class="text-xs px-1.5 py-0.5 rounded {{ $m->type==='in' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">{{ ucfirst($m->type) }}</span></td>
                    <td class="px-4 py-2">{{ $m->mode_of_payment }}</td>
                    <td class="px-4 py-2 text-slate-600">{{ $m->reason ?? '—' }}</td>
                    <td class="px-4 py-2 text-right font-medium {{ $m->type==='in' ? 'text-green-600' : 'text-red-600' }}">{{ $m->type==='in' ? '+' : '−' }}{{ number_format($m->amount, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    @if ($shift->status === 'open')
    <div class="flex gap-2">
        <a href="{{ route('shifts.summary', $shift) }}" class="rounded-lg bg-red-600 hover:bg-red-700 text-white text-sm font-medium px-4 py-2">Close shift</a>
        <a href="{{ route('pos.register') }}" class="rounded-lg bg-slate-200 hover:bg-slate-300 text-slate-700 text-sm font-medium px-4 py-2">Back to register</a>
    </div>
    @endif
</div>
@endsection
