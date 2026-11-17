@extends('layouts.app')
@section('title', 'Close Shift')

@section('content')
<div class="max-w-4xl mx-auto">
    <div class="flex items-center justify-between mb-4">
        <h1 class="text-xl font-bold text-slate-800">Shift summary — {{ $shift->shift_no }}</h1>
        <a href="{{ route('pos.register') }}" class="text-sm text-slate-500 hover:text-slate-700">← Back to register</a>
    </div>

    @include('pos.partials.flash')

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-xs text-slate-500 uppercase tracking-wide">Profile</p>
            <p class="font-semibold text-slate-800 mt-1">{{ $shift->posProfile?->name }}</p>
            <p class="text-xs text-slate-500 mt-2">Started {{ $shift->started_at?->format('M j, Y H:i') }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-xs text-slate-500 uppercase tracking-wide">Invoices</p>
            <p class="text-2xl font-bold text-slate-800 mt-1">{{ $invoices['count'] }}</p>
            <p class="text-xs text-slate-500 mt-1">{{ $invoices['total_qty'] }} items sold</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-4">
            <p class="text-xs text-slate-500 uppercase tracking-wide">Grand total</p>
            <p class="text-2xl font-bold text-brand-700 mt-1">{{ \App\Support\Money::format($invoices['grand_total'], 2) }}</p>
            <p class="text-xs text-slate-500 mt-1">Net {{ \App\Support\Money::format($invoices['net_total'], 2) }} · Tax {{ \App\Support\Money::format($invoices['tax_total'], 2) }}</p>
        </div>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 mb-6">
        <div class="px-4 py-3 border-b border-slate-100">
            <h2 class="font-semibold text-slate-700">Reconciliation — count the drawer</h2>
            <p class="text-xs text-slate-500">Enter the counted amount per mode. Expected is computed from opening + collected + cash-in − cash-out.</p>
        </div>
        <form method="POST" action="{{ route('shifts.close', $shift) }}" id="close-form">
            @csrf
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-xs text-slate-500 uppercase tracking-wide border-b border-slate-100">
                        <th class="text-left px-4 py-2">Mode</th>
                        <th class="text-right px-4 py-2">Opening</th>
                        <th class="text-right px-4 py-2">Collected</th>
                        <th class="text-right px-4 py-2">Cash In</th>
                        <th class="text-right px-4 py-2">Cash Out</th>
                        <th class="text-right px-4 py-2">Expected</th>
                        <th class="text-right px-4 py-2">Counted</th>
                        <th class="text-right px-4 py-2">Diff</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($payments as $row)
                    <tr class="border-b border-slate-50">
                        <td class="px-4 py-2 font-medium text-slate-700">{{ $row['mode_of_payment'] }}</td>
                        <td class="text-right px-4 py-2 text-slate-600">{{ \App\Support\Money::format($row['opening_amount'], 2) }}</td>
                        <td class="text-right px-4 py-2 text-slate-600">{{ \App\Support\Money::format($row['collected_amount'], 2) }}</td>
                        <td class="text-right px-4 py-2 text-green-600">+{{ \App\Support\Money::format($row['cash_in_amount'], 2) }}</td>
                        <td class="text-right px-4 py-2 text-red-600">−{{ \App\Support\Money::format($row['cash_out_amount'], 2) }}</td>
                        <td class="text-right px-4 py-2 font-semibold text-slate-800">{{ \App\Support\Money::format($row['expected_amount'], 2) }}</td>
                        <td class="text-right px-4 py-2">
                            <input type="number" step="0.01" min="0" data-expected="{{ $row['expected_amount'] }}"
                                   name="payments[{{ $loop->index }}][counted_amount]"
                                   value="{{ \App\Support\Money::plain($row['expected_amount'], 2) }}"
                                   class="w-28 text-right rounded border border-slate-300 px-2 py-1 no-spin counted-input">
                            <input type="hidden" name="payments[{{ $loop->index }}][mode_of_payment]" value="{{ $row['mode_of_payment'] }}">
                        </td>
                        <td class="text-right px-4 py-2 diff-cell font-medium">0</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="px-4 py-3 border-t border-slate-100">
                <label class="block text-sm font-medium text-slate-700 mb-1">Closing note (optional)</label>
                <textarea name="note" rows="2" class="w-full rounded-lg border border-slate-300 px-3 py-2" placeholder="Discrepancies, incidents, anything to flag..."></textarea>
            </div>

            <div class="px-4 py-3 border-t border-slate-100 flex justify-end gap-2">
                <a href="{{ route('shifts.show', $shift) }}" class="px-4 py-2 text-slate-600 hover:text-slate-800">View shift</a>
                <button type="submit" class="px-5 py-2 rounded-lg bg-red-600 hover:bg-red-700 text-white font-medium">Close shift</button>
            </div>
        </form>
    </div>

    @if ($cashMovements->isNotEmpty())
    <div class="bg-white rounded-xl border border-slate-200">
        <div class="px-4 py-3 border-b border-slate-100"><h2 class="font-semibold text-slate-700">Cash movements ({{ $cashMovements->count() }})</h2></div>
        <table class="w-full text-sm">
            <thead><tr class="text-xs text-slate-500 uppercase tracking-wide border-b border-slate-100">
                <th class="text-left px-4 py-2">When</th><th class="text-left px-4 py-2">Type</th><th class="text-left px-4 py-2">Mode</th><th class="text-left px-4 py-2">Reason</th><th class="text-right px-4 py-2">Amount</th>
            </tr></thead>
            <tbody>
                @foreach ($cashMovements as $m)
                <tr class="border-b border-slate-50">
                    <td class="px-4 py-2 text-slate-500">{{ $m->created_at?->format('H:i') }}</td>
                    <td class="px-4 py-2"><span class="text-xs px-1.5 py-0.5 rounded {{ $m->type==='in' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">{{ ucfirst($m->type) }}</span></td>
                    <td class="px-4 py-2">{{ $m->mode_of_payment }}</td>
                    <td class="px-4 py-2 text-slate-600">{{ $m->reason ?? '—' }}</td>
                    <td class="px-4 py-2 text-right font-medium {{ $m->type==='in' ? 'text-green-600' : 'text-red-600' }}">{{ $m->type==='in' ? '+' : '−' }}{{ \App\Support\Money::format($m->amount, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-4 bg-white rounded-xl border border-slate-200 p-4">
        <form method="POST" action="{{ route('shifts.cash-movement', $shift) }}" class="flex flex-wrap items-end gap-3">
            @csrf
            <div><label class="block text-xs text-slate-500">Type</label>
                <select name="type" class="rounded border border-slate-300 px-2 py-1.5 text-sm"><option value="in">Cash In</option><option value="out">Cash Out</option></select>
            </div>
            <div><label class="block text-xs text-slate-500">Mode</label>
                <select name="mode_of_payment" class="rounded border border-slate-300 px-2 py-1.5 text-sm">
                    @foreach ($payments as $row)<option>{{ $row['mode_of_payment'] }}</option>@endforeach
                </select>
            </div>
            <div><label class="block text-xs text-slate-500">Amount</label>
                <input type="number" step="0.01" min="0.01" name="amount" required class="w-28 rounded border border-slate-300 px-2 py-1.5 text-sm no-spin">
            </div>
            <div class="flex-1"><label class="block text-xs text-slate-500">Reason</label>
                <input type="text" name="reason" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
            </div>
            <button class="rounded-lg bg-slate-700 hover:bg-slate-800 text-white text-sm font-medium px-3 py-1.5">Add</button>
        </form>
    </div>
    @endif
</div>

<script>
    const inputs = document.querySelectorAll('.counted-input');
    const diffs = document.querySelectorAll('.diff-cell');
    inputs.forEach((inp, i) => {
        inp.addEventListener('input', () => {
            const expected = parseFloat(inp.dataset.expected);
            const counted = parseFloat(inp.value) || 0;
            const diff = counted - expected;
            const cell = diffs[i];
            cell.textContent = (diff >= 0 ? '+' : '') + String(Number(diff.toFixed(2)));
            cell.classList.remove('text-green-600','text-red-600','text-slate-500');
            cell.classList.add(diff > 0.001 ? 'text-green-600' : diff < -0.001 ? 'text-red-600' : 'text-slate-500');
        });
    });
</script>
@endsection
