@extends('layouts.app')
@section('title', "Return {$invoice->invoice_no}")

@php
    $currency = \App\Models\PosProfile::symbolFor($invoice->currency);
    $precision = 2;
@endphp

@section('content')
<div class="max-w-3xl mx-auto">
    <h1 class="text-xl font-bold text-slate-800 mb-4">Return against {{ $invoice->invoice_no }}</h1>
    @include('pos.partials.flash')

    <div class="bg-amber-50 border border-amber-200 rounded-lg p-3 mb-4 text-sm text-amber-800">
        Select the items being returned and their quantities. The return will create a new invoice with negative totals and restore stock to the warehouse.
    </div>

    <form method="POST" action="{{ route('invoices.return', $invoice) }}" class="bg-white rounded-xl border border-slate-200 p-6 space-y-4">
        @csrf

        <table class="w-full text-sm">
            <thead><tr class="text-xs text-slate-500 uppercase tracking-wide border-b border-slate-100">
                <th class="text-left px-2 py-2">Item</th><th class="text-center px-2 py-2">Sold qty</th><th class="text-center px-2 py-2">Return qty</th>
            </tr></thead>
            <tbody>
                @php $idx = 0; @endphp
                @foreach ($invoice->items as $line)
                <tr class="border-b border-slate-50">
                    <td class="px-2 py-2"><p class="font-medium">{{ $line->item_name }}</p><p class="text-xs text-slate-400">{{ $line->item_code }} · sold {{ number_format($line->qty, $precision) }} @ {{ number_format($line->rate, $precision) }}</p></td>
                    <td class="text-center px-2 py-2 text-slate-600">{{ number_format(abs($line->qty), $precision) }}</td>
                    <td class="text-center px-2 py-2">
                        <input type="checkbox" data-line="{{ $idx }}" class="return-toggle" checked>
                        <input type="number" step="0.001" min="0.01" max="{{ abs($line->qty) }}"
                               name="items[{{ $idx }}][qty]" value="{{ abs($line->qty) }}"
                               data-max="{{ abs($line->qty) }}"
                               class="return-qty w-20 text-center rounded border border-slate-300 px-1 py-0.5 no-spin ml-2">
                        <input type="hidden" name="items[{{ $idx }}][item_id]" value="{{ $line->item_id }}">
                    </td>
                </tr>
                @php $idx++; @endphp
                @endforeach
            </tbody>
        </table>

        <div class="border-t border-slate-100 pt-4">
            <p class="text-sm font-medium text-slate-700 mb-2">Refund method</p>
            <div class="space-y-2">
                @foreach ($invoice->payments as $p)
                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="refund_modes[]" value="{{ $p->mode_of_payment }}" class="refund-mode" data-amount="{{ $p->amount }}" checked>
                    Refund to <strong>{{ $p->mode_of_payment }}</strong> — original payment {{ number_format($p->amount, $precision) }}
                    <input type="number" step="0.01" min="0" name="refund_amounts[]" value="{{ number_format(abs($p->amount), 2, '.', '') }}" class="refund-amount w-28 rounded border border-slate-300 px-2 py-0.5 no-spin">
                    <input type="hidden" name="payments[][mode_of_payment]" value="{{ $p->mode_of_payment }}" class="refund-mode-hidden">
                </label>
                @endforeach
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Reason (optional)</label>
            <input type="text" name="reason" class="w-full rounded-lg border border-slate-300 px-3 py-2" placeholder="Defective, wrong item, customer change of mind...">
        </div>

        <div class="flex justify-end gap-2 pt-2">
            <a href="{{ route('invoices.show', $invoice) }}" class="px-4 py-2 text-slate-600 hover:text-slate-800">Cancel</a>
            <button class="px-5 py-2 rounded-lg bg-amber-600 hover:bg-amber-700 text-white font-medium">Process return</button>
        </div>
    </form>
</div>

<script>
    document.querySelectorAll('.return-toggle').forEach(cb => {
        cb.addEventListener('change', () => {
            const qty = cb.closest('tr').querySelector('.return-qty');
            qty.disabled = !cb.checked;
            if (!cb.checked) qty.value = 0;
        });
    });
    document.querySelectorAll('.refund-mode').forEach((cb, i) => {
        cb.addEventListener('change', () => {
            const amt = cb.closest('label').querySelector('.refund-amount');
            const hidden = cb.closest('label').querySelector('.refund-mode-hidden');
            amt.disabled = !cb.checked;
            hidden.disabled = !cb.checked;
            if (!cb.checked) amt.value = 0;
        });
    });
</script>
@endsection
