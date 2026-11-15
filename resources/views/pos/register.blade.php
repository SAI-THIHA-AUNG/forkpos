@extends('layouts.app')
@section('title', 'Register')

@php
    $currency = $setting->currency_symbol ?? '$';
    $precision = $setting->currency_precision ?? 2;
    $priceList = $profile->selling_price_list ?: 'Standard Selling';
@endphp

@section('content')
<div class="grid grid-cols-1 lg:grid-cols-[1fr_22rem] gap-4 lg:h-[calc(100vh-5.5rem)]">

    {{-- Left: catalog --}}
    <div class="flex flex-col gap-3 min-h-0">
        {{-- Search + barcode + view toggle --}}
        <div class="flex flex-wrap gap-2">
            <input id="search" type="search" placeholder="Search items or scan barcode…" autofocus
                   class="flex-1 rounded-lg border border-slate-300 px-3 py-2 focus:border-brand-500 focus:ring-brand-500 outline-none">
            <select id="group-filter" class="rounded-lg border border-slate-300 px-3 py-2 bg-white">
                <option value="">All categories</option>
                @foreach ($groups as $g)
                <option value="{{ $g->id }}">{{ $g->name }}</option>
                @endforeach
            </select>
            <div class="flex rounded-lg border border-slate-300 overflow-hidden">
                <button type="button" data-view="card" class="view-btn px-3 py-2 text-sm bg-brand-600 text-white">Card</button>
                <button type="button" data-view="list" class="view-btn px-3 py-2 text-sm bg-white text-slate-600">List</button>
            </div>
        </div>

        {{-- Category tiles --}}
        <div id="category-tiles" class="flex gap-2 overflow-x-auto pb-1"></div>

        {{-- Product grid --}}
        <div id="product-grid" class="flex-1 overflow-y-auto bg-white rounded-xl border border-slate-200 p-3">
            <div id="items" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-5 gap-3">
                {{-- seeded by JS --}}
            </div>
            <div id="loading" class="text-center text-slate-400 text-sm py-8">No items found</div>
        </div>
    </div>

    {{-- Right: cart --}}
    <div class="flex flex-col bg-white rounded-xl border border-slate-200 min-h-0 max-lg:min-h-96">
        <div class="px-4 py-3 border-b border-slate-100">
            <div class="flex items-center justify-between">
                <h2 class="font-semibold text-slate-700">Current Sale</h2>
                <button id="clear-cart" class="text-xs text-slate-400 hover:text-red-600">Clear</button>
            </div>
            @if ($currentShift)
            <p class="text-xs text-slate-500 mt-0.5">Shift {{ $currentShift->shift_no }} · started {{ $currentShift->started_at?->format('H:i') }}</p>
            @else
            <p class="text-xs text-amber-600 mt-0.5">No shift required by this profile</p>
            @endif
        </div>

        <div id="cart" class="flex-1 overflow-y-auto px-2 py-2">
            <p id="cart-empty" class="text-center text-slate-400 text-sm py-8">Scan or tap items to add</p>
        </div>

        <div class="border-t border-slate-100 px-4 py-3 space-y-2 text-sm">
            <div class="flex justify-between"><span class="text-slate-500">Subtotal</span><span id="subtotal">{{ $currency }}0.00</span></div>
            <div class="flex justify-between"><span class="text-slate-500">Discount</span><span id="discount">{{ $currency }}0.00</span></div>
            <div class="flex justify-between"><span class="text-slate-500">Tax</span><span id="tax">{{ $currency }}0.00</span></div>
            <div class="flex justify-between font-bold text-lg border-t border-slate-100 pt-2"><span>Total</span><span id="grand">{{ $currency }}0.00</span></div>
        </div>

        <div class="border-t border-slate-100 px-4 py-3 space-y-2">
            <input id="coupon" type="text" placeholder="Coupon code (optional)" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
            <input id="customer-search" type="search" placeholder="Customer (defaults to Walk-in)" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm" value="Walk-in Customer" data-customer-id="">
            <div id="customer-results" class="hidden bg-white border border-slate-200 rounded mt-1 max-h-40 overflow-y-auto text-sm"></div>
        </div>

        <div class="border-t border-slate-100 px-4 py-3">
            <p class="text-xs text-slate-500 mb-1">Payment</p>
            <div id="payments" class="space-y-2">
                {{-- payment rows --}}
            </div>
            <button id="add-payment" class="mt-2 text-xs text-brand-600 hover:underline">+ Add payment method</button>

            <div class="flex justify-between text-sm mt-3"><span>Change / outstanding</span><span id="change" class="font-medium">{{ $currency }}0.00</span></div>
        </div>

        <div class="border-t border-slate-100 p-3">
            <button id="checkout" class="w-full rounded-lg bg-brand-600 hover:bg-brand-700 text-white font-semibold py-2.5 disabled:opacity-50" disabled>Charge</button>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
const CURRENCY = @json($currency);
const PRECISION = {{ $precision }};
const PROFILE = {
    id: {{ $profile->id }},
    tax_inclusive: {{ $profile->tax_inclusive ? 'true' : 'false' }},
    allow_rate_change: {{ $profile->allow_rate_change ? 'true' : 'false' }},
    allow_discount_change: {{ $profile->allow_discount_change ? 'true' : 'false' }},
    max_discount_percentage: {{ (float) $profile->max_discount_percentage }},
    shift_id: {{ $currentShift?->id ?? 'null' }},
};
@php
    $defaultTaxPayload = $defaultTaxes->isNotEmpty() ? [
        'id' => $defaultTaxes->first()->id,
        'rate' => (float) $defaultTaxes->first()->rate,
        'included_in_price' => (bool) $defaultTaxes->first()->included_in_price,
    ] : null;
    $paymentModesPayload = $profile->payments->map(fn($p) => [
        'mode_of_payment' => $p->mode_of_payment,
        'is_default' => (bool) $p->is_default,
        'allow_edit_amount' => (bool) $p->allow_edit_amount,
    ])->values();
    $seedItemsPayload = $items->map(fn($i) => [
        'id' => $i->id,
        'item_code' => $i->item_code,
        'item_name' => $i->item_name,
        'item_group_id' => $i->item_group_id,
        'item_group' => $i->group?->name,
        'uom' => $i->salesUom?->uom_name ?? $i->stockUom?->uom_name ?? 'Unit',
        'rate' => (float) $i->effectiveRate($priceList),
        'allow_discount' => (bool) $i->allow_discount,
        'is_stock_item' => (bool) $i->is_stock_item,
        'stock_qty' => (float) ($stockMap[$i->id] ?? 0),
    ])->values();
    $groupsPayload = $groups->map(fn($g) => ['id' => $g->id, 'name' => $g->name])->values();
@endphp
const DEFAULT_TAX = @json($defaultTaxPayload);
const PAYMENT_MODES = @json($paymentModesPayload);
const SEED_ITEMS = @json($seedItemsPayload);
const GROUPS = @json($groupsPayload);

let cart = []; // [{item_id, item_code, item_name, uom, qty, rate, price_list_rate, discount_percentage, tax_id, stock_qty}]

// ---- Render category tiles ---------------------------------------------
(function renderCategories() {
    const el = document.getElementById('category-tiles');
    el.innerHTML = GROUPS.map(g =>
        `<button class="shrink-0 px-3 py-1.5 rounded-full text-xs bg-slate-100 hover:bg-brand-100 text-slate-700" data-group="${g.id}">${g.name}</button>`
    ).join('');
    el.querySelectorAll('button').forEach(b => b.addEventListener('click', () => {
        document.getElementById('group-filter').value = b.dataset.group;
        renderItems();
    }));
})();

// ---- Render product grid ------------------------------------------------
let viewMode = 'card';

function renderItems() {
    const q = document.getElementById('search').value.trim().toLowerCase();
    const groupId = document.getElementById('group-filter').value;
    const items = SEED_ITEMS.filter(i => {
        if (groupId && String(i.item_group_id) !== String(groupId)) return false;
        if (q && !i.item_name.toLowerCase().includes(q) && !i.item_code.toLowerCase().includes(q)) return false;
        return true;
    });
    const grid = document.getElementById('items');
    document.getElementById('loading').style.display = items.length ? 'none' : 'block';

    // Switch container between card grid and list
    grid.className = viewMode === 'list'
        ? 'divide-y divide-slate-100'
        : 'grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 xl:grid-cols-5 gap-3';

    grid.innerHTML = items.map(i => viewMode === 'list' ? renderListItem(i) : renderCardItem(i)).join('');
    grid.querySelectorAll('button').forEach(b => b.addEventListener('click', () => addToCart(parseInt(b.dataset.id))));
}

function renderCardItem(i) {
    return `
        <button class="text-left p-3 rounded-lg border border-slate-200 hover:border-brand-500 hover:shadow-sm transition bg-white group" data-id="${i.id}">
            <div class="aspect-square mb-2 rounded bg-gradient-to-br from-slate-100 to-slate-200 flex items-center justify-center text-slate-400 text-2xl font-bold">${i.item_name.charAt(0)}</div>
            <p class="text-xs font-medium text-slate-700 line-clamp-2 group-hover:text-brand-700">${i.item_name}</p>
            <div class="flex items-center justify-between mt-1">
                <span class="text-sm font-semibold text-brand-700">${CURRENCY}${i.rate.toFixed(PRECISION)}</span>
                ${i.is_stock_item ? `<span class="text-[10px] ${i.stock_qty > 0 ? 'text-green-600' : 'text-red-500'}">${i.stock_qty > 0 ? i.stock_qty+' '+i.uom : 'out'}</span>` : ''}
            </div>
        </button>
    `;
}

function renderListItem(i) {
    return `
        <button class="w-full text-left px-3 py-2 hover:bg-slate-50 transition flex items-center gap-3" data-id="${i.id}">
            <span class="w-8 h-8 rounded bg-gradient-to-br from-slate-100 to-slate-200 flex items-center justify-center text-slate-400 text-sm font-bold shrink-0">${i.item_name.charAt(0)}</span>
            <span class="flex-1 min-w-0">
                <span class="block text-sm font-medium text-slate-700 truncate">${i.item_name}</span>
                <span class="block text-xs text-slate-400 truncate">${i.item_code}</span>
            </span>
            <span class="text-sm font-semibold text-brand-700 shrink-0">${CURRENCY}${i.rate.toFixed(PRECISION)}</span>
            ${i.is_stock_item ? `<span class="text-[10px] ${i.stock_qty > 0 ? 'text-green-600' : 'text-red-500'} shrink-0 w-12 text-right">${i.stock_qty > 0 ? i.stock_qty+' '+i.uom : 'out'}</span>` : ''}
        </button>
    `;
}

document.getElementById('search').addEventListener('input', renderItems);
document.getElementById('group-filter').addEventListener('change', renderItems);

// View toggle (card / list)
document.querySelectorAll('.view-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        viewMode = btn.dataset.view;
        document.querySelectorAll('.view-btn').forEach(b => {
            const active = b.dataset.view === viewMode;
            b.className = 'view-btn px-3 py-2 text-sm ' + (active ? 'bg-brand-600 text-white' : 'bg-white text-slate-600');
        });
        renderItems();
    });
});

renderItems();

// ---- Barcode scanning --------------------------------------------------
document.getElementById('search').addEventListener('keydown', e => {
    if (e.key !== 'Enter') return;
    e.preventDefault();
    const code = e.target.value.trim();
    if (!code) return;
    // First try exact item match
    const found = SEED_ITEMS.find(i => i.item_code === code);
    if (found) { addToCart(found.id); e.target.value = ''; return; }
    // Otherwise treat as a barcode and fetch
    fetch(`/pos/barcode/${encodeURIComponent(code)}`)
        .then(r => r.ok ? r.json() : null)
        .then(res => {
            if (res?.data) {
                // Add to seed cache so the tile shows up too
                SEED_ITEMS.unshift(res.data);
                addToCart(res.data.id);
                e.target.value = '';
            } else {
                flash('No item for barcode ' + code, 'error');
            }
        });
});

// ---- Cart --------------------------------------------------------------
function addToCart(itemId) {
    const item = SEED_ITEMS.find(i => i.id === itemId);
    if (!item) return;
    const existing = cart.find(c => c.item_id === itemId);
    if (existing) {
        existing.qty += 1;
    } else {
        cart.push({
            item_id: item.id,
            item_code: item.item_code,
            item_name: item.item_name,
            uom: item.uom,
            qty: 1,
            price_list_rate: item.rate,
            rate: item.rate,
            discount_percentage: 0,
            tax_id: DEFAULT_TAX?.id ?? null,
            stock_qty: item.stock_qty,
        });
    }
    renderCart();
}

function renderCart() {
    const el = document.getElementById('cart');
    if (!cart.length) {
        el.innerHTML = `<p id="cart-empty" class="text-center text-slate-400 text-sm py-8">Scan or tap items to add</p>`;
        document.getElementById('checkout').disabled = true;
    } else {
        el.innerHTML = cart.map((c, i) => `
            <div class="flex items-center gap-2 py-2 border-b border-slate-50">
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-medium text-slate-700 truncate">${c.item_name}</p>
                    <p class="text-xs text-slate-500">${CURRENCY}${c.rate.toFixed(PRECISION)} / ${c.uom}
                        ${c.discount_percentage > 0 ? ` · -${c.discount_percentage}%` : ''}
                    </p>
                </div>
                <div class="flex items-center gap-1">
                    <button data-qty="${i}" data-delta="-1" class="w-6 h-6 rounded bg-slate-100 hover:bg-slate-200 text-slate-600">−</button>
                    <input type="number" step="0.001" value="${c.qty}" data-qty-input="${i}" class="w-14 text-center rounded border border-slate-200 px-1 py-0.5 text-sm no-spin">
                    <button data-qty="${i}" data-delta="1" class="w-6 h-6 rounded bg-slate-100 hover:bg-slate-200 text-slate-600">+</button>
                </div>
                <span class="w-20 text-right text-sm font-medium text-slate-700">${CURRENCY}${(c.rate * c.qty).toFixed(PRECISION)}</span>
                <button data-remove="${i}" class="text-slate-300 hover:text-red-500">×</button>
            </div>
        `).join('');
        el.querySelectorAll('[data-qty]').forEach(b => b.addEventListener('click', () => changeQty(parseInt(b.dataset.qty), parseInt(b.dataset.delta))));
        el.querySelectorAll('[data-qty-input]').forEach(inp => inp.addEventListener('change', () => setQty(parseInt(inp.dataset.qtyInput), parseFloat(inp.value) || 0)));
        el.querySelectorAll('[data-remove]').forEach(b => b.addEventListener('click', () => { cart.splice(parseInt(b.dataset.remove), 1); renderCart(); }));
        document.getElementById('checkout').disabled = false;
    }
    recalcTotals();
}

function changeQty(idx, delta) {
    cart[idx].qty = Math.max(0.001, cart[idx].qty + delta);
    if (cart[idx].qty <= 0.001) cart.splice(idx, 1);
    renderCart();
}
function setQty(idx, val) {
    if (val <= 0) { cart.splice(idx, 1); } else { cart[idx].qty = val; }
    renderCart();
}
document.getElementById('clear-cart').addEventListener('click', () => { if (confirm('Clear the cart?')) { cart = []; renderCart(); } });

// ---- Totals ------------------------------------------------------------
function recalcTotals() {
    let subtotal = 0, tax = 0, discount = 0;
    cart.forEach(c => {
        const lineAmount = c.price_list_rate * c.qty;
        const lineDisc = c.discount_percentage > 0 ? lineAmount * c.discount_percentage / 100 : 0;
        const netLine = lineAmount - lineDisc;
        subtotal += netLine;
        discount += lineDisc;
        if (DEFAULT_TAX && c.tax_id) {
            const rate = DEFAULT_TAX.rate;
            if (DEFAULT_TAX.included_in_price) {
                tax += netLine - (netLine / (1 + rate / 100));
            } else {
                tax += netLine * rate / 100;
            }
        }
    });
    document.getElementById('subtotal').textContent = CURRENCY + subtotal.toFixed(PRECISION);
    document.getElementById('discount').textContent = CURRENCY + discount.toFixed(PRECISION);
    document.getElementById('tax').textContent = CURRENCY + tax.toFixed(PRECISION);
    document.getElementById('grand').textContent = CURRENCY + (subtotal + tax).toFixed(PRECISION);
    recalcChange();
}

// ---- Payments ----------------------------------------------------------
function defaultPaymentRow() {
    const def = PAYMENT_MODES.find(p => p.is_default) ?? PAYMENT_MODES[0];
    return { mode_of_payment: def?.mode_of_payment ?? 'Cash', amount: 0 };
}
let payments = [defaultPaymentRow()];

function renderPayments() {
    const el = document.getElementById('payments');
    el.innerHTML = payments.map((p, i) => `
        <div class="flex items-center gap-2">
            <select data-pay-mode="${i}" class="flex-1 rounded border border-slate-300 px-2 py-1.5 text-sm bg-white">
                ${PAYMENT_MODES.map(m => `<option ${m.mode_of_payment===p.mode_of_payment?'selected':''}>${m.mode_of_payment}</option>`).join('')}
            </select>
            <input type="number" step="0.01" min="0" data-pay-amount="${i}" value="${p.amount || ''}" placeholder="0.00" class="w-24 text-right rounded border border-slate-300 px-2 py-1.5 text-sm no-spin">
            ${payments.length > 1 ? `<button data-pay-remove="${i}" class="text-slate-300 hover:text-red-500">×</button>` : ''}
        </div>
    `).join('');
    el.querySelectorAll('[data-pay-mode]').forEach(s => s.addEventListener('change', () => { payments[parseInt(s.dataset.payMode)].mode_of_payment = s.value; }));
    el.querySelectorAll('[data-pay-amount]').forEach(inp => {
        inp.addEventListener('input', () => { payments[parseInt(inp.dataset.payAmount)].amount = parseFloat(inp.value) || 0; recalcChange(); });
    });
    el.querySelectorAll('[data-pay-remove]').forEach(b => b.addEventListener('click', () => { payments.splice(parseInt(b.dataset.payRemove), 1); renderPayments(); }));
    recalcChange();
}

function recalcChange() {
    const grand = parseFloat(document.getElementById('grand').textContent.replace(/[^0-9.]/g, '')) || 0;
    const paid = payments.reduce((s, p) => s + (p.amount || 0), 0);
    const diff = paid - grand;
    const el = document.getElementById('change');
    el.textContent = CURRENCY + Math.abs(diff).toFixed(PRECISION);
    el.classList.remove('text-green-600','text-red-600','text-slate-500');
    el.classList.add(diff >= 0 ? 'text-green-600' : 'text-red-600');
    el.textContent = (diff >= 0 ? 'Change: ' : 'Due: ') + CURRENCY + Math.abs(diff).toFixed(PRECISION);
}
document.getElementById('add-payment').addEventListener('click', () => {
    payments.push(defaultPaymentRow());
    renderPayments();
});
renderPayments();

// Pre-fill cash payment with the grand total when items are added.
const observer = new MutationObserver(() => {
    const grand = parseFloat(document.getElementById('grand').textContent.replace(/[^0-9.]/g, '')) || 0;
    if (payments.length === 1 && payments[0].mode_of_payment === (PAYMENT_MODES.find(p=>p.is_default)?.mode_of_payment ?? 'Cash')) {
        payments[0].amount = grand;
        renderPayments();
    }
});
observer.observe(document.getElementById('grand'), { childList: true, subtree: false, characterData: true });

// ---- Customer search ---------------------------------------------------
const cs = document.getElementById('customer-search');
const csResults = document.getElementById('customer-results');
let csTimer;
cs.addEventListener('input', () => {
    clearTimeout(csTimer);
    const q = cs.value.trim();
    if (!q || q === 'Walk-in Customer') { cs.dataset.customerId = ''; csResults.classList.add('hidden'); return; }
    csTimer = setTimeout(() => {
        fetch(`{{ route('customers.index') }}?q=${encodeURIComponent(q)}`, { headers: { 'Accept': 'application/json' } })
            .then(r => r.json()).then(res => {
                const data = res.data || res;
                if (!Array.isArray(data) || !data.length) { csResults.classList.add('hidden'); return; }
                csResults.innerHTML = data.slice(0, 8).map(c => `<button data-id="${c.id}" data-name="${c.customer_name}" class="block w-full text-left px-3 py-2 hover:bg-slate-100">${c.customer_name} <span class="text-xs text-slate-400">${c.customer_code ?? ''}</span></button>`).join('');
                csResults.classList.remove('hidden');
                csResults.querySelectorAll('button').forEach(b => b.addEventListener('click', () => {
                    cs.dataset.customerId = b.dataset.id;
                    cs.value = b.dataset.name;
                    csResults.classList.add('hidden');
                }));
            });
    }, 250);
});
document.addEventListener('click', e => { if (!csResults.contains(e.target) && e.target !== cs) csResults.classList.add('hidden'); });

// ---- Checkout ----------------------------------------------------------
document.getElementById('checkout').addEventListener('click', () => {
    if (!cart.length) return;
    const grand = parseFloat(document.getElementById('grand').textContent.replace(/[^0-9.]/g, '')) || 0;
    const paid = payments.reduce((s, p) => s + (p.amount || 0), 0);
    if (paid < grand - 0.01) {
        if (!confirm('Payment is short of total. Submit as unpaid invoice?')) return;
    }
    const payload = {
        customer_id: cs.dataset.customerId || null,
        coupon_code: document.getElementById('coupon').value.trim() || null,
        items: cart.map(c => ({
            item_id: c.item_id,
            qty: c.qty,
            uom: c.uom,
            rate: c.rate,
            discount_percentage: c.discount_percentage || null,
            tax_id: c.tax_id,
        })),
        payments: payments.filter(p => p.amount > 0).map(p => ({
            mode_of_payment: p.mode_of_payment,
            amount: p.amount,
        })),
    };
    fetch('{{ route('pos.checkout') }}', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
        body: JSON.stringify(payload),
    }).then(r => r.json()).then(res => {
        if (res.errors) {
            const msgs = Object.values(res.errors).flat().join('\n');
            alert('Checkout failed:\n' + msgs);
            return;
        }
        const id = res.data?.invoice_id;
        if (id) {
            window.location.href = `/pos/receipt/${id}?fresh=1`;
        } else {
            alert('Unexpected response: ' + JSON.stringify(res));
        }
    }).catch(err => alert('Network error: ' + err));
});

// ---- Flash helper ------------------------------------------------------
function flash(msg, type='info') {
    // Minimal — the page renders flash on next navigation anyway.
    console.log('[' + type + ']', msg);
}
</script>
@endsection
