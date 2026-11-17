@extends('layouts.app')
@section('title', 'Open Shift')

@section('content')
<div class="max-w-2xl mx-auto">
    <h1 class="text-xl font-bold text-slate-800 mb-4">Open a shift</h1>

    @include('pos.partials.flash')

    @if ($current)
    <div class="mb-4 p-3 rounded-lg bg-amber-50 border border-amber-200 text-amber-800 text-sm">
        You already have an open shift ({{ $current->shift_no }}) on {{ $current->posProfile?->name }}.
        <a href="{{ route('shifts.summary', $current) }}" class="underline font-medium">View summary &amp; close →</a>
    </div>
    @endif

    <form method="POST" action="{{ route('shifts.open') }}" class="bg-white rounded-xl border border-slate-200 p-6 space-y-5">
        @csrf

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">POS Profile</label>
            <select name="pos_profile_id" class="w-full rounded-lg border border-slate-300 px-3 py-2" required>
                @foreach ($profiles as $p)
                <option value="{{ $p['id'] }}" @selected($selectedProfileId == $p['id'])>
                    {{ $p['name'] }} — {{ $p['company'] }} ({{ $p['currency'] }})
                </option>
                @endforeach
            </select>
        </div>

        <div id="balances" class="space-y-2">
            <p class="text-sm font-medium text-slate-700">Opening balances</p>
            <p class="text-xs text-slate-500 -mt-1">Count the cash and floats in the till before starting. Leave 0 if the drawer is empty.</p>
            @php($activeProfile = $profiles->firstWhere('id', (int) $selectedProfileId) ?? $profiles->first())
            @foreach ($activeProfile['payments'] ?? [] as $pay)
            <div class="flex items-center gap-3">
                <label class="w-40 text-sm text-slate-600">{{ $pay['mode_of_payment'] }}</label>
                <input type="number" step="0.01" min="0" name="balances[{{ $loop->index }}][amount]" value="0" class="flex-1 rounded-lg border border-slate-300 px-3 py-1.5 no-spin" placeholder="0.00">
                <input type="hidden" name="balances[{{ $loop->index }}][mode_of_payment]" value="{{ $pay['mode_of_payment'] }}">
                @if($pay['is_default'])<span class="text-[10px] px-1.5 py-0.5 rounded bg-amber-100 text-amber-700">default</span>@endif
            </div>
            @endforeach
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Opening note (optional)</label>
            <textarea name="opening_note" rows="2" class="w-full rounded-lg border border-slate-300 px-3 py-2" placeholder="Anything worth noting before the shift starts..."></textarea>
        </div>

        <div class="flex justify-end gap-2 pt-2">
            <a href="{{ route('home') }}" class="px-4 py-2 text-slate-600 hover:text-slate-800">Cancel</a>
            <button type="submit" class="px-5 py-2 rounded-lg bg-brand-600 hover:bg-brand-700 text-white font-medium">Open shift</button>
        </div>
    </form>
</div>

<script>
    // When the profile select changes, swap the opening balances to match.
    const select = document.querySelector('select[name="pos_profile_id"]');
    const profiles = @json($profiles);
    const balancesEl = document.getElementById('balances');
    const rowsTemplate = (payments) => payments.map((p, i) => `
        <div class="flex items-center gap-3">
            <label class="w-40 text-sm text-slate-600">${p.mode_of_payment}</label>
            <input type="number" step="0.01" min="0" name="balances[${i}][amount]" value="0" class="flex-1 rounded-lg border border-slate-300 px-3 py-1.5 no-spin" placeholder="0.00">
            <input type="hidden" name="balances[${i}][mode_of_payment]" value="${p.mode_of_payment}">
            ${p.is_default ? '<span class="text-[10px] px-1.5 py-0.5 rounded bg-amber-100 text-amber-700">default</span>' : ''}
        </div>`).join('');
    select?.addEventListener('change', () => {
        const id = parseInt(select.value);
        const p = profiles.find(x => x.id === id);
        balancesEl.innerHTML = `<p class="text-sm font-medium text-slate-700">Opening balances</p>
            <p class="text-xs text-slate-500 -mt-1">Count the cash and floats in the till before starting. Leave 0 if the drawer is empty.</p>`
            + rowsTemplate(p?.payments || []);
    });
</script>
@endsection
