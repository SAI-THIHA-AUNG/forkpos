@extends('layouts.app')
@section('title', 'Preparing register')

@section('content')
<div class="max-w-xl mx-auto mt-10">
    <h1 class="text-xl font-bold text-slate-800 mb-1">Preparing {{ $profile->name }}</h1>
    <p class="text-sm text-slate-500 mb-6">Pulling this profile's items, prices, stock and customers from ERPNext. The register opens automatically.</p>

    @include('pos.partials.flash')

    <ul id="steps" class="bg-white rounded-xl border border-slate-200 divide-y divide-slate-100 text-sm"></ul>

    <form id="retry" method="POST" action="{{ route('pos.preparing.retry') }}" class="mt-4 hidden">
        @csrf
        <button class="rounded-lg bg-slate-800 hover:bg-slate-900 text-white text-sm font-medium px-4 py-2">Retry</button>
        <span class="text-xs text-slate-500 ml-2">A step failed — check the message above, then retry.</span>
    </form>
    <p class="mt-4 text-xs text-slate-400">Large catalogs can take a few minutes the first time; later syncs only fetch what changed.</p>
</div>

<script>
const LABELS = { warehouse: 'Warehouse', tax: 'Tax template', item: 'Items', item_price: 'Prices', stock: 'Stock', customer: 'Customers' };
const ICON = { pending: '○', running: '⏳', done: '✅', failed: '❌' };
function render(p) {
    document.getElementById('steps').innerHTML = p.steps.map(s => `
        <li class="px-4 py-3">
            <div class="flex items-center justify-between">
                <span>${ICON[s.status] ?? '○'} ${LABELS[s.entity] ?? s.entity}</span>
                <span class="text-xs text-slate-500">${s.status === 'done' ? s.rows + ' updated' : s.status}</span>
            </div>
            ${s.error ? `<p class="text-xs text-red-600 mt-1 break-words"></p>` : ''}
        </li>`).join('');
    document.querySelectorAll('#steps li').forEach((li, i) => {
        const e = li.querySelector('p'); if (e) e.textContent = p.steps[i].error;
    });
    document.getElementById('retry').classList.toggle('hidden', !p.failed);
}
async function poll() {
    try {
        const r = await fetch(@json(route('pos.preparing.status')), { headers: { Accept: 'application/json' } });
        const p = (await r.json()).data;
        render(p);
        if (p.ready) { window.location = @json(route('pos.register')); return; }
    } catch (e) {}
    setTimeout(poll, 2000);
}
render(@json($progress));
poll();
</script>
@endsection
