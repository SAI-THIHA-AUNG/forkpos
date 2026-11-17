@extends('layouts.app')
@section('title', 'Register')

@php
    $currency = $profile->effectiveCurrencySymbol($setting);
    $precision = $profile->effectivePrecision($setting);
    $priceList = $profile->selling_price_list;
@endphp

@section('content')
<div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_28rem] xl:grid-cols-[minmax(0,1fr)_32rem] 2xl:grid-cols-[minmax(0,1fr)_36rem] gap-4 lg:h-[calc(100vh-5.5rem)]">

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
            <div id="items" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 2xl:grid-cols-5 gap-3">
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

        <div class="border-t border-slate-100 px-4 py-3 space-y-1.5 text-sm">
            <div class="flex justify-between"><span class="text-slate-500">Subtotal</span><span id="subtotal">{{ $currency }}0</span></div>
            <div class="flex justify-between"><span class="text-slate-500">Line discounts</span><span id="discount" class="text-red-600">{{ $currency }}0</span></div>
            @if ($profile->allow_discount_change)
            <div class="flex items-center justify-between gap-2">
                <span class="text-slate-500">Total discount</span>
                <div class="flex items-center gap-1">
                    <select id="addl-type" title="Discount type" class="rounded border border-slate-200 px-1 py-0.5 text-xs bg-white">
                        <option value="percentage">%</option>
                        <option value="amount">Amt</option>
                    </select>
                    <input id="addl-value" type="number" min="0" step="0.01" placeholder="0" title="Discount on the whole sale"
                           class="w-20 text-right rounded border border-slate-200 px-1.5 py-0.5 text-sm no-spin">
                    <span id="addl-amount" class="w-24 text-right text-red-600">{{ $currency }}0</span>
                </div>
            </div>
            @endif
            <div class="flex justify-between"><span class="text-slate-500">Tax</span><span id="tax">{{ $currency }}0</span></div>
            <div class="flex justify-between font-bold text-lg border-t border-slate-100 pt-2"><span>Total</span><span id="grand">{{ $currency }}0</span></div>
        </div>

        <div class="border-t border-slate-100 px-4 py-3 space-y-2">
            <input id="coupon" type="text" placeholder="Coupon code (optional)" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
            <input id="customer-search" type="search" placeholder="Customer (defaults to Walk-in)" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm" value="Walk-in Customer" data-customer-id="">
            <div id="customer-results" class="hidden bg-white border border-slate-200 rounded mt-1 max-h-40 overflow-y-auto text-sm"></div>
        </div>

        <div class="border-t border-slate-100 px-4 py-3">
            <label class="flex items-center gap-2 text-sm font-medium text-slate-700 cursor-pointer">
                <input id="credit-toggle" type="checkbox" class="rounded border-slate-300 text-brand-600">
                Credit sale (customer pays later)
            </label>
            <div id="credit-panel" class="hidden mt-2 space-y-2">
                <p class="text-xs text-slate-500">Payment due in</p>
                <div class="flex flex-wrap items-center gap-1.5">
                    @foreach (\App\Models\Invoice::CREDIT_TERM_DAYS as $days)
                    <button type="button" data-credit-days="{{ $days }}" class="credit-term px-3 py-1 rounded-full border border-slate-300 text-xs text-slate-600">{{ $days }} days</button>
                    @endforeach
                    <button type="button" data-credit-days="custom" class="credit-term px-3 py-1 rounded-full border border-slate-300 text-xs text-slate-600">Pick date</button>
                </div>
                <input id="credit-date" type="date" class="hidden w-full rounded border border-slate-300 px-2 py-1.5 text-sm" min="{{ now()->toDateString() }}">
                <p id="credit-due" class="text-xs text-slate-500"></p>
                <p class="text-xs text-slate-400">Choose a customer above. Enter any amount paid now below (cash or digital); the rest stays due.</p>
            </div>
        </div>

        <div class="border-t border-slate-100 px-4 py-3">
            <p class="text-xs text-slate-500 mb-1">Payment</p>
            <div id="payments" class="space-y-2">
                {{-- payment rows --}}
            </div>
            <button id="add-payment" class="mt-2 text-xs text-brand-600 hover:underline">+ Add payment method</button>

            <div class="flex justify-between text-sm mt-3"><span>Change / outstanding</span><span id="change" class="font-medium">{{ $currency }}0</span></div>
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

const esc = v => String(v ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
// ERPNext gives the precision; ForkPOS hides forced decimals in the UI:
// 1,200.00 -> "1200", 1.25 -> "1.25". Math still uses the raw numbers.
const fmtAmount = n => String(Number((Number(n) || 0).toFixed(PRECISION)));
let GRAND = 0;                                   // current invoice total (number)
let addlDiscount = { type: 'percentage', value: 0 }; // whole-sale discount

let cart = []; // [{item_id, item_code, item_name, uom, qty, rate, price_list_rate, allow_discount, discount_type, discount_value, tax_id, stock_qty}]

// ---- Render category tiles ---------------------------------------------
(function renderCategories() {
    const el = document.getElementById('category-tiles');
    el.innerHTML = GROUPS.map(g =>
        `<button class="shrink-0 px-3 py-1.5 rounded-full text-xs bg-slate-100 hover:bg-brand-100 text-slate-700" data-group="${g.id}">${esc(g.name)}</button>`
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
        : 'grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 2xl:grid-cols-5 gap-3';

    grid.innerHTML = items.map(i => viewMode === 'list' ? renderListItem(i) : renderCardItem(i)).join('');
    grid.querySelectorAll('button').forEach(b => b.addEventListener('click', () => addToCart(parseInt(b.dataset.id))));
}

function renderCardItem(i) {
    return `
        <button class="text-left p-3 rounded-lg border border-slate-200 hover:border-brand-500 hover:shadow-sm transition bg-white group" data-id="${i.id}">
            <div class="aspect-square mb-2 rounded bg-gradient-to-br from-slate-100 to-slate-200 flex items-center justify-center text-slate-400 text-2xl font-bold">${i.item_name.charAt(0)}</div>
            <p class="text-xs font-medium text-slate-700 line-clamp-2 group-hover:text-brand-700">${esc(i.item_name)}</p>
            <div class="flex items-center justify-between mt-1">
                <span class="text-sm font-semibold text-brand-700">${CURRENCY}${fmtAmount(i.rate)}</span>
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
                <span class="block text-sm font-medium text-slate-700 truncate">${esc(i.item_name)}</span>
                <span class="block text-xs text-slate-400 truncate">${esc(i.item_code)}</span>
            </span>
            <span class="text-sm font-semibold text-brand-700 shrink-0">${CURRENCY}${fmtAmount(i.rate)}</span>
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
            allow_discount: !!item.allow_discount,
            discount_type: 'percentage',
            discount_value: 0,
            tax_id: DEFAULT_TAX?.id ?? null,
            stock_qty: item.stock_qty,
        });
    }
    renderCart();
}

// Line maths, shared by the cart renderer, the totals and the checkout payload.
function lineGross(c) { return Math.max(0, (c.rate || 0) * c.qty); }
function lineDiscount(c) {
    const gross = lineGross(c);
    if (!c.allow_discount || !c.discount_value) return 0;
    if (c.discount_type === 'amount') return Math.min(gross, c.discount_value);
    return Math.min(gross, gross * c.discount_value / 100);
}
function lineNet(c) { return Math.max(0, lineGross(c) - lineDiscount(c)); }

// Price / discount editors: rendered only when the ERPNext POS profile allows
// them (allow_rate_change / allow_discount_change) and, for discounts, only
// when the item itself is discountable.
function priceDiscountControls(c, i) {
    const rateControl = PROFILE.allow_rate_change ? `
        <label class="text-xs text-slate-400">Price</label>
        <input type="number" min="0" step="0.01" value="${c.rate}" data-line-rate="${i}" title="Unit price"
               class="w-20 rounded border border-slate-200 px-1.5 py-1 text-sm no-spin">
    ` : '';
    const discountControl = (PROFILE.allow_discount_change && c.allow_discount) ? `
        <select data-line-disc-type="${i}" title="Discount type" class="rounded border border-slate-200 px-1 py-1 text-sm bg-white">
            <option value="percentage" ${c.discount_type === 'percentage' ? 'selected' : ''}>%</option>
            <option value="amount" ${c.discount_type === 'amount' ? 'selected' : ''}>Amt</option>
        </select>
        <input type="number" min="0" step="0.01" value="${c.discount_value || ''}" placeholder="0" data-line-disc="${i}" title="Line discount"
               class="w-16 rounded border border-slate-200 px-1.5 py-1 text-sm no-spin">
    ` : '';
    if (!rateControl && !discountControl) return '';
    return `<div class="flex items-center gap-1.5">${rateControl}${discountControl}</div>`;
}

function renderCart() {
    const el = document.getElementById('cart');
    if (!cart.length) {
        el.innerHTML = `<p id="cart-empty" class="text-center text-slate-400 text-sm py-8">Scan or tap items to add</p>`;
        document.getElementById('checkout').disabled = true;
    } else {
        el.innerHTML = cart.map((c, i) => `
            <div class="py-2.5 px-1 border-b border-slate-100">
                <div class="flex items-start gap-2">
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-medium text-slate-800 leading-snug break-words">${esc(c.item_name)}</p>
                        <p class="text-xs text-slate-500">${CURRENCY}${fmtAmount(c.rate)} / ${esc(c.uom)}
                            ${c.discount_value > 0 && c.allow_discount ? `<span class="text-red-600">−${c.discount_type === 'percentage' ? c.discount_value + '%' : CURRENCY + c.discount_value}</span>` : ''}
                        </p>
                    </div>
                    <span data-line-amount="${i}" class="text-sm font-semibold text-slate-800 whitespace-nowrap">${CURRENCY}${fmtAmount(lineNet(c))}</span>
                    <button data-remove="${i}" title="Remove" class="text-slate-300 hover:text-red-500 leading-none px-1">✕</button>
                </div>
                <div class="mt-1.5 flex flex-wrap items-center gap-x-4 gap-y-1.5">
                    <div class="flex items-center gap-1">
                        <button data-qty="${i}" data-delta="-1" class="w-7 h-7 rounded bg-slate-100 hover:bg-slate-200 text-slate-600">−</button>
                        <input type="number" step="0.001" value="${c.qty}" data-qty-input="${i}" class="w-16 text-center rounded border border-slate-200 px-1 py-1 text-sm no-spin">
                        <button data-qty="${i}" data-delta="1" class="w-7 h-7 rounded bg-slate-100 hover:bg-slate-200 text-slate-600">+</button>
                    </div>
                    ${priceDiscountControls(c, i)}
                </div>
            </div>
        `).join('');
        el.querySelectorAll('[data-qty]').forEach(b => b.addEventListener('click', () => changeQty(parseInt(b.dataset.qty), parseInt(b.dataset.delta))));
        el.querySelectorAll('[data-qty-input]').forEach(inp => inp.addEventListener('change', () => setQty(parseInt(inp.dataset.qtyInput), parseFloat(inp.value) || 0)));
        el.querySelectorAll('[data-remove]').forEach(b => b.addEventListener('click', () => { cart.splice(parseInt(b.dataset.remove), 1); renderCart(); }));
        el.querySelectorAll('[data-line-rate]').forEach(inp => inp.addEventListener('input', () => {
            const idx = parseInt(inp.dataset.lineRate);
            const val = parseFloat(inp.value);
            cart[idx].rate = (isNaN(val) || val < 0) ? 0 : val;
            recalcTotals();
        }));
        // Re-render once editing settles so the line caption shows the new price / discount.
        el.querySelectorAll('[data-line-rate], [data-line-disc]').forEach(inp => inp.addEventListener('change', () => renderCart()));
        el.querySelectorAll('[data-line-disc-type]').forEach(sel => sel.addEventListener('change', () => {
            const idx = parseInt(sel.dataset.lineDiscType);
            cart[idx].discount_type = sel.value;
            cart[idx].discount_value = 0;
            renderCart();
        }));
        el.querySelectorAll('[data-line-disc]').forEach(inp => inp.addEventListener('input', () => {
            const idx = parseInt(inp.dataset.lineDisc);
            let val = parseFloat(inp.value) || 0;
            if (val < 0) val = 0;
            const line = cart[idx];
            if (PROFILE.max_discount_percentage > 0) {
                const gross = lineGross(line);
                const cap = line.discount_type === 'percentage'
                    ? PROFILE.max_discount_percentage
                    : gross * PROFILE.max_discount_percentage / 100;
                if (val > cap) {
                    val = cap;
                    inp.value = val;
                    flash('Maximum discount is ' + PROFILE.max_discount_percentage + '%', 'error');
                }
            }
            line.discount_value = val;
            recalcTotals();
        }));
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
document.getElementById('clear-cart').addEventListener('click', () => {
    if (!confirm('Clear the cart?')) return;
    cart = [];
    addlDiscount = { type: 'percentage', value: 0 };
    const av = document.getElementById('addl-value'); if (av) av.value = '';
    const at = document.getElementById('addl-type'); if (at) at.value = 'percentage';
    renderCart();
});

// ---- Totals ------------------------------------------------------------
// Mirrors InvoiceService: line amounts -> net + tax, then the whole-sale
// discount is taken off the tax-inclusive total and spread over net and tax.
function recalcTotals() {
    let gross = 0, lineDisc = 0, net = 0, tax = 0;
    cart.forEach((c, i) => {
        const amount = lineNet(c);
        gross += lineGross(c);
        lineDisc += lineDiscount(c);
        let n = amount, t = 0;
        if (DEFAULT_TAX && c.tax_id) {
            const rate = DEFAULT_TAX.rate;
            if (DEFAULT_TAX.included_in_price) { n = amount / (1 + rate / 100); t = amount - n; }
            else { t = amount * rate / 100; }
        }
        net += n; tax += t;
        const lineEl = document.querySelector(`[data-line-amount="${i}"]`);
        if (lineEl) lineEl.textContent = CURRENCY + fmtAmount(amount);
    });

    const pre = net + tax;
    let addl = 0;
    if (PROFILE.allow_discount_change && addlDiscount.value > 0 && pre > 0) {
        addl = addlDiscount.type === 'percentage'
            ? pre * Math.min(100, addlDiscount.value) / 100
            : Math.min(pre, addlDiscount.value);
    }
    const keep = pre > 0 ? 1 - addl / pre : 1;
    tax *= keep;
    GRAND = net * keep + tax;

    document.getElementById('subtotal').textContent = CURRENCY + fmtAmount(gross);
    document.getElementById('discount').textContent = (lineDisc > 0 ? '−' : '') + CURRENCY + fmtAmount(lineDisc);
    const addlEl = document.getElementById('addl-amount');
    if (addlEl) addlEl.textContent = (addl > 0 ? '−' : '') + CURRENCY + fmtAmount(addl);
    document.getElementById('tax').textContent = CURRENCY + fmtAmount(tax);
    document.getElementById('grand').textContent = CURRENCY + fmtAmount(GRAND);
    autofillSinglePayment();
}

// Whole-sale discount inputs (respect the profile's max discount %).
(function bindTotalDiscount() {
    const typeEl = document.getElementById('addl-type');
    const valEl = document.getElementById('addl-value');
    if (!typeEl || !valEl) return;
    typeEl.addEventListener('change', () => {
        addlDiscount = { type: typeEl.value, value: 0 };
        valEl.value = '';
        recalcTotals();
    });
    valEl.addEventListener('input', () => {
        let val = Math.max(0, parseFloat(valEl.value) || 0);
        if (PROFILE.max_discount_percentage > 0) {
            const pre = cart.reduce((sum, c) => sum + lineNet(c), 0); // approx. basis for the cap
            const cap = addlDiscount.type === 'percentage' ? PROFILE.max_discount_percentage : pre * PROFILE.max_discount_percentage / 100;
            if (val > cap) {
                val = cap;
                valEl.value = Number(val.toFixed(2));
                flash('Maximum discount is ' + PROFILE.max_discount_percentage + '%', 'error');
            }
        }
        addlDiscount.value = val;
        recalcTotals();
    });
})();

// ---- Credit sale -------------------------------------------------------
// credit = { on, days: 7|14|30|'custom'|null, date: 'YYYY-MM-DD' }
let credit = { on: false, days: null, date: '' };

function isoDate(d) {
    const z = n => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${z(d.getMonth() + 1)}-${z(d.getDate())}`;
}
function creditDueDate() {
    if (!credit.on) return '';
    if (credit.days === 'custom') return credit.date;
    if (!credit.days) return '';
    const d = new Date();
    d.setDate(d.getDate() + credit.days);
    return isoDate(d);
}
function renderCredit() {
    document.getElementById('credit-panel').classList.toggle('hidden', !credit.on);
    document.querySelectorAll('.credit-term').forEach(b => {
        const active = String(credit.days) === b.dataset.creditDays;
        b.classList.toggle('bg-brand-600', active);
        b.classList.toggle('text-white', active);
        b.classList.toggle('border-brand-600', active);
        b.classList.toggle('text-slate-600', !active);
    });
    document.getElementById('credit-date').classList.toggle('hidden', credit.days !== 'custom');
    const due = creditDueDate();
    document.getElementById('credit-due').textContent = due ? 'Due on ' + due : 'Pick the payment terms';
    document.getElementById('checkout').textContent = credit.on ? 'Charge on credit' : 'Charge';
}
document.getElementById('credit-toggle').addEventListener('change', e => {
    credit.on = e.target.checked;
    // A credit sale starts with nothing paid; a cash sale pre-fills the full total again.
    payments = [defaultPaymentRow()];
    if (!credit.on) { credit.days = null; credit.date = ''; }
    renderCredit();
    renderPayments();
    autofillSinglePayment();
});
document.querySelectorAll('.credit-term').forEach(b => b.addEventListener('click', () => {
    credit.days = b.dataset.creditDays === 'custom' ? 'custom' : parseInt(b.dataset.creditDays);
    renderCredit();
}));
document.getElementById('credit-date').addEventListener('change', e => { credit.date = e.target.value; renderCredit(); });

// ---- Payments ----------------------------------------------------------
function defaultPaymentRow() {
    const def = PAYMENT_MODES.find(p => p.is_default) ?? PAYMENT_MODES[0];
    return { mode_of_payment: def?.mode_of_payment ?? '', amount: 0, reference_no: '' };
}
let payments = [defaultPaymentRow()];
// Anything that is not cash (KBZPay, Wave, bank transfer, card...) gets an optional reference field.
const isDigitalMode = mode => !/cash/i.test(mode || '');

function renderPayments() {
    const el = document.getElementById('payments');
    el.innerHTML = payments.map((p, i) => `
        <div>
            <div class="flex items-center gap-2">
                <select data-pay-mode="${i}" class="flex-1 rounded border border-slate-300 px-2 py-1.5 text-sm bg-white">
                    ${PAYMENT_MODES.map(m => `<option ${m.mode_of_payment===p.mode_of_payment?'selected':''}>${esc(m.mode_of_payment)}</option>`).join('')}
                </select>
                <input type="number" step="0.01" min="0" data-pay-amount="${i}" value="${p.amount || ''}" placeholder="0" class="w-24 text-right rounded border border-slate-300 px-2 py-1.5 text-sm no-spin">
                ${payments.length > 1 ? `<button data-pay-remove="${i}" class="text-slate-300 hover:text-red-500">×</button>` : ''}
            </div>
            ${isDigitalMode(p.mode_of_payment) ? `<input type="text" maxlength="64" data-pay-ref="${i}" value="${esc(p.reference_no)}" placeholder="Reference / transaction no. (optional)" class="mt-1 w-full rounded border border-slate-200 px-2 py-1 text-xs">` : ''}
        </div>
    `).join('');
    el.querySelectorAll('[data-pay-mode]').forEach(s => s.addEventListener('change', () => {
        payments[parseInt(s.dataset.payMode)].mode_of_payment = s.value;
        renderPayments();
    }));
    el.querySelectorAll('[data-pay-ref]').forEach(inp => inp.addEventListener('input', () => { payments[parseInt(inp.dataset.payRef)].reference_no = inp.value; }));
    el.querySelectorAll('[data-pay-amount]').forEach(inp => {
        inp.addEventListener('input', () => { payments[parseInt(inp.dataset.payAmount)].amount = parseFloat(inp.value) || 0; recalcChange(); });
    });
    el.querySelectorAll('[data-pay-remove]').forEach(b => b.addEventListener('click', () => { payments.splice(parseInt(b.dataset.payRemove), 1); renderPayments(); }));
    recalcChange();
}

function autofillSinglePayment() {
    // Pre-fill a lone default payment row with the total, like cash-tender POS screens.
    if (credit.on) { recalcChange(); return; } // credit: the whole total stays due unless a down payment is typed
    const defMode = (PAYMENT_MODES.find(p => p.is_default) ?? PAYMENT_MODES[0])?.mode_of_payment;
    if (payments.length === 1 && payments[0].mode_of_payment === defMode) {
        payments[0].amount = Number(GRAND.toFixed(PRECISION));
        renderPayments();
    } else {
        recalcChange();
    }
}

function recalcChange() {
    const grand = GRAND;
    const paid = payments.reduce((s, p) => s + (p.amount || 0), 0);
    const diff = paid - grand;
    const el = document.getElementById('change');
    el.textContent = CURRENCY + fmtAmount(Math.abs(diff));
    el.classList.remove('text-green-600','text-red-600','text-slate-500');
    el.classList.add(diff >= 0 ? 'text-green-600' : 'text-red-600');
    el.textContent = (diff >= 0 ? 'Change: ' : (credit.on ? 'On credit: ' : 'Due: ')) + CURRENCY + fmtAmount(Math.abs(diff));
}
document.getElementById('add-payment').addEventListener('click', () => {
    payments.push(defaultPaymentRow());
    renderPayments();
});
renderPayments();
renderCredit();

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
    const grand = GRAND;
    const paid = payments.reduce((s, p) => s + (p.amount || 0), 0);
    if (credit.on) {
        if (!cs.dataset.customerId) { flash('Choose a customer for a credit sale.', 'error'); return; }
        if (!creditDueDate()) { flash('Pick the payment terms: 7, 14, 30 days or a date.', 'error'); return; }
        if (paid >= grand - 0.01) { flash('Payments already cover the total — untick Credit sale.', 'error'); return; }
    } else if (paid < grand - 0.01) {
        if (!confirm('Payment is short of total. Submit as unpaid invoice?')) return;
    }
    const payload = {
        customer_id: cs.dataset.customerId || null,
        coupon_code: document.getElementById('coupon').value.trim() || null,
        additional_discount_percentage: addlDiscount.type === 'percentage' && addlDiscount.value > 0 ? addlDiscount.value : null,
        additional_discount_amount: addlDiscount.type === 'amount' && addlDiscount.value > 0 ? addlDiscount.value : null,
        items: cart.map(c => ({
            item_id: c.item_id,
            qty: c.qty,
            uom: c.uom,
            rate: c.rate,
            discount_percentage: c.discount_type === 'percentage' && c.discount_value > 0 ? c.discount_value : null,
            discount_amount: c.discount_type === 'amount' && c.discount_value > 0 ? c.discount_value : null,
            tax_id: c.tax_id,
        })),
        is_credit: credit.on,
        credit_days: credit.on && credit.days !== 'custom' ? credit.days : null,
        due_date: credit.on && credit.days === 'custom' ? credit.date : null,
        payments: payments.filter(p => p.amount > 0).map(p => ({
            mode_of_payment: p.mode_of_payment,
            amount: p.amount,
            reference_no: p.reference_no || null,
        })),
    };
    fetch('{{ route('pos.checkout') }}', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
        body: JSON.stringify(payload),
    }).then(async r => {
        const res = await r.json().catch(() => ({}));
        if (!r.ok) {
            const msgs = res.errors ? Object.values(res.errors).flat().join(' ') : (res.message || ('HTTP ' + r.status));
            flash(msgs, 'error');
            return;
        }
        const id = res.data?.invoice_id;
        if (id) {
            window.location.href = `/pos/receipt/${id}?fresh=1`;
        } else {
            flash('Unexpected response: ' + JSON.stringify(res), 'error');
        }
    }).catch(err => flash('Network error: ' + err.message, 'error'));
});

// ---- Flash helper ------------------------------------------------------
let flashTimer;
function flash(msg, type='info') {
    let el = document.getElementById('pos-flash');
    if (!el) {
        el = document.createElement('div');
        el.id = 'pos-flash';
        document.body.appendChild(el);
    }
    el.textContent = msg;
    el.className = 'fixed top-4 left-1/2 -translate-x-1/2 z-50 max-w-md px-4 py-2 rounded-lg shadow-lg text-sm font-medium transition-opacity duration-300 '
        + (type === 'error' ? 'bg-red-600 text-white' : 'bg-slate-800 text-white');
    el.style.opacity = '1';
    clearTimeout(flashTimer);
    flashTimer = setTimeout(() => { el.style.opacity = '0'; }, 3500);
}
</script>
@endsection
