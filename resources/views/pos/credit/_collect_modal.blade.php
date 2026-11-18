{{-- Shared "collect payment" dialog. Open with: openCollect({id, invoice_no, outstanding, customer}) --}}
@php($collectModes = request()->attributes->get('posProfile')?->paymentModes() ?: ['Cash'])
<div id="collect-modal" class="hidden fixed inset-0 z-[60] bg-black/40 flex items-center justify-center p-4 no-print">
    <form id="collect-form" class="bg-white rounded-xl shadow-xl w-full max-w-sm p-5 space-y-3">
        <div>
            <h3 class="font-semibold text-slate-800">Collect payment</h3>
            <p class="text-xs text-slate-500"><span id="collect-inv" class="font-mono"></span> · <span id="collect-cust"></span></p>
        </div>
        <div class="flex justify-between text-sm"><span class="text-slate-500">Outstanding</span><span id="collect-out" class="font-semibold"></span></div>
        <label class="block text-sm">Amount
            <input id="collect-amount" type="number" step="0.01" min="0.01" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2 text-lg">
        </label>
        <label class="block text-sm">Payment mode
            <select id="collect-mode" class="mt-1 w-full rounded border border-slate-300 px-3 py-2 bg-white">
                @foreach ($collectModes as $m)<option value="{{ $m }}">{{ $m }}</option>@endforeach
            </select>
        </label>
        <label class="block text-sm">Reference (cheque / transfer no.)
            <input id="collect-ref" type="text" maxlength="64" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
        </label>
        <p id="collect-error" class="hidden text-sm text-red-600"></p>
        <div class="flex gap-2 pt-1">
            <button type="button" id="collect-cancel" class="flex-1 rounded-lg border border-slate-300 py-2 text-sm hover:bg-slate-50">Cancel</button>
            <button type="submit" id="collect-submit" class="flex-1 rounded-lg bg-green-600 hover:bg-green-700 text-white font-semibold py-2 text-sm disabled:opacity-50">Collect</button>
        </div>
    </form>
</div>
<script>
(function () {
    const modal = document.getElementById('collect-modal');
    const form = document.getElementById('collect-form');
    const err = document.getElementById('collect-error');
    let current = null, key = null;
    const uuid = () => (window.crypto && crypto.randomUUID) ? crypto.randomUUID()
        : 'c-' + Date.now() + '-' + Math.random().toString(16).slice(2);

    window.openCollect = function (inv) {
        current = inv; key = uuid(); // one key per dialog → a double click can't collect twice
        document.getElementById('collect-inv').textContent = inv.invoice_no;
        document.getElementById('collect-cust').textContent = inv.customer || '';
        document.getElementById('collect-out').textContent = Number(inv.outstanding).toFixed(2);
        const amt = document.getElementById('collect-amount');
        amt.value = Number(inv.outstanding).toFixed(2);
        amt.max = Number(inv.outstanding).toFixed(2);
        document.getElementById('collect-ref').value = '';
        err.classList.add('hidden');
        modal.classList.remove('hidden');
        amt.focus(); amt.select();
    };
    const close = () => modal.classList.add('hidden');
    document.getElementById('collect-cancel').addEventListener('click', close);
    modal.addEventListener('click', e => { if (e.target === modal) close(); });

    form.addEventListener('submit', async e => {
        e.preventDefault();
        const btn = document.getElementById('collect-submit');
        btn.disabled = true; err.classList.add('hidden');
        try {
            const r = await fetch(`/credit/${current.id}/collect`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}' },
                body: JSON.stringify({
                    amount: document.getElementById('collect-amount').value,
                    mode_of_payment: document.getElementById('collect-mode').value,
                    reference_no: document.getElementById('collect-ref').value || null,
                    offline_id: key,
                }),
            });
            const res = await r.json().catch(() => ({}));
            if (!r.ok) {
                err.textContent = res.errors ? Object.values(res.errors).flat().join(' ') : (res.message || ('HTTP ' + r.status));
                err.classList.remove('hidden'); btn.disabled = false; return;
            }
            close(); btn.disabled = false;
            if (typeof window.onCollected === 'function') window.onCollected(res.data); else window.location.reload();
        } catch (ex) {
            err.textContent = 'Network error: ' + ex.message; err.classList.remove('hidden'); btn.disabled = false;
        }
    });
})();
</script>
