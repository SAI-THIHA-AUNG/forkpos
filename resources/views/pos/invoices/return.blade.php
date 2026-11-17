@extends('layouts.app')
@section('title', "Return {$invoice->invoice_no}")

@php
    $currency = \App\Models\PosProfile::symbolFor($invoice->currency);
    $precision = 2;
    $fmt = fn ($n) => \App\Support\Money::quantity($n);
@endphp

@section('content')
<div class="max-w-4xl mx-auto">
    <a href="{{ route('invoices.show', $invoice) }}" class="inline-block text-sm text-slate-500 hover:text-slate-700 mb-3">&larr; Back to {{ $invoice->invoice_no }}</a>
    <h1 class="text-xl font-bold text-slate-800 mb-1">Sales return</h1>
    <p class="text-sm text-slate-500 mb-4">
        {{ $invoice->invoice_no }} · {{ $invoice->posting_datetime?->format('M j, Y H:i') }} · {{ $invoice->customer?->customer_name ?? 'Walk-in' }}
    </p>
    @include('pos.partials.flash')

    @if ($errors->any())
    <div class="mb-4 p-3 rounded-lg border bg-red-50 border-red-200 text-red-800 text-sm space-y-1">
        @foreach ($errors->all() as $message)<p>{{ $message }}</p>@endforeach
    </div>
    @endif

    <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 mb-4 text-sm text-amber-800">
        Enter the quantity coming back for each item. Items are refunded at the price the customer actually paid
        (after line and total discounts) and stock goes back to the warehouse.
    </div>

    <form method="POST" action="{{ route('invoices.return', $invoice) }}" class="bg-white rounded-xl border border-slate-200 p-5 space-y-5" id="return-form">
        @csrf

        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="text-xs text-slate-500 uppercase tracking-wide border-b border-slate-100">
                <th class="text-left px-2 py-2">Item</th>
                <th class="text-right px-2 py-2">Sold</th>
                <th class="text-right px-2 py-2">Returned</th>
                <th class="text-right px-2 py-2">Left</th>
                <th class="text-right px-2 py-2">Paid price</th>
                <th class="text-center px-2 py-2">Return qty</th>
                <th class="text-right px-2 py-2">Refund</th>
            </tr></thead>
            <tbody>
                @foreach ($lines as $idx => $line)
                @php
                    $unit = $line['tax_included'] ? $line['rate'] : $line['rate'] * (1 + $line['tax_rate'] / 100);
                    $old = old("items.$idx.qty");
                @endphp
                <tr class="border-b border-slate-50 {{ $line['remaining'] <= 0.0001 ? 'opacity-50' : '' }}">
                    <td class="px-2 py-2"><p class="font-medium">{{ $line['item_name'] }}</p><p class="text-xs text-slate-400">{{ $line['item_code'] }} · {{ $line['uom'] }}</p></td>
                    <td class="text-right px-2 py-2 text-slate-600">{{ $fmt($line['sold']) }}</td>
                    <td class="text-right px-2 py-2 text-slate-600">{{ $fmt($line['returned']) }}</td>
                    <td class="text-right px-2 py-2 font-medium">{{ $fmt($line['remaining']) }}</td>
                    <td class="text-right px-2 py-2">{{ \App\Support\Money::format($unit, $precision) }}</td>
                    <td class="text-center px-2 py-2">
                        <input type="hidden" name="items[{{ $idx }}][item_id]" value="{{ $line['item_id'] }}">
                        <div class="inline-flex items-center gap-1">
                            <input type="number" step="0.001" min="0" max="{{ $line['remaining'] }}"
                                   name="items[{{ $idx }}][qty]" value="{{ $old ?? 0 }}"
                                   data-max="{{ $line['remaining'] }}" data-unit="{{ $unit }}"
                                   {{ $line['remaining'] <= 0.0001 ? 'disabled' : '' }}
                                   class="return-qty w-20 text-center rounded border border-slate-300 px-1 py-1 no-spin">
                            @if ($line['remaining'] > 0.0001)
                            <button type="button" class="return-all text-xs text-brand-600 hover:underline">All</button>
                            @endif
                        </div>
                    </td>
                    <td class="text-right px-2 py-2 font-medium line-refund">{{ \App\Support\Money::format(0, $precision) }}</td>
                </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr><td colspan="6" class="text-right px-2 py-2 font-semibold">Total refund</td>
                    <td class="text-right px-2 py-2 font-bold text-amber-700" id="refund-total">{{ $currency }}0</td></tr>
            </tfoot>
        </table>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 border-t border-slate-100 pt-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Refund method</label>
                <select name="refund_mode" class="w-full rounded-lg border border-slate-300 px-3 py-2 bg-white">
                    @foreach ($refundModes as $mode)
                    <option value="{{ $mode }}" @selected(old('refund_mode', $defaultMode) === $mode)>{{ $mode }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Refund amount</label>
                <input type="number" step="0.01" min="0" name="refund_amount" id="refund-amount" value="{{ old('refund_amount', 0) }}"
                       class="w-full rounded-lg border border-slate-300 px-3 py-2 text-right no-spin">
                <p class="text-xs text-slate-400 mt-1">Follows the total refund; change it for a partial refund or store credit (0 = nothing paid out).</p>
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Reason (optional)</label>
            <input type="text" name="reason" value="{{ old('reason') }}" class="w-full rounded-lg border border-slate-300 px-3 py-2" placeholder="Defective, wrong item, customer change of mind…">
        </div>

        <div class="flex justify-end gap-2 pt-1">
            <a href="{{ route('invoices.show', $invoice) }}" class="px-4 py-2 text-slate-600 hover:text-slate-800">Cancel</a>
            <button id="return-submit" class="px-5 py-2 rounded-lg bg-amber-600 hover:bg-amber-700 text-white font-medium disabled:opacity-50" disabled>Process return</button>
        </div>
    </form>
</div>
@endsection

@section('scripts')
<script>
(function () {
    const CURRENCY = @json($currency);
    // Trim forced decimals for display: 4.00 -> 4, 2.16 -> 2.16.
    const fmt = (n) => String(Number(n.toFixed(2)));
    const qtyInputs = [...document.querySelectorAll('.return-qty')];
    const amountEl = document.getElementById('refund-amount');
    let amountTouched = false;
    amountEl.addEventListener('input', () => { amountTouched = true; });

    function recalc() {
        let total = 0;
        qtyInputs.forEach(inp => {
            const max = parseFloat(inp.dataset.max) || 0;
            let q = parseFloat(inp.value) || 0;
            if (q > max) { q = max; inp.value = q; }
            if (q < 0) { q = 0; inp.value = 0; }
            const line = q * (parseFloat(inp.dataset.unit) || 0);
            inp.closest('tr').querySelector('.line-refund').textContent = fmt(line);
            total += line;
        });
        document.getElementById('refund-total').textContent = CURRENCY + fmt(total);
        if (!amountTouched) amountEl.value = fmt(total);
        document.getElementById('return-submit').disabled = !(total > 0);
    }
    qtyInputs.forEach(inp => inp.addEventListener('input', recalc));
    document.querySelectorAll('.return-all').forEach(btn => btn.addEventListener('click', () => {
        const inp = btn.closest('td').querySelector('.return-qty');
        inp.value = inp.dataset.max;
        recalc();
    }));
    recalc();
})();
</script>
@endsection
