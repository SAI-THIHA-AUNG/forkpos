<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Jobs\SyncProfileCatalog;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\ItemBarcode;
use App\Models\ItemGroup;
use App\Models\PosDraft;
use App\Models\PosProfile;
use App\Models\Setting;
use App\Models\Tax;
use App\Services\InvoiceService;
use App\Services\ProfileCatalogSync;
use App\Services\ShiftService;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PosController extends Controller
{
    public function __construct(
        protected InvoiceService $invoices,
        protected ShiftService $shifts,
        protected StockService $stock,
        protected ProfileCatalogSync $catalog,
    ) {}

    /**
     * GET /pos — the register screen.
     * Renders the product grid + cart shell; the JS layer fetches items via
     * /pos/items and posts checkout via /pos/invoice (same controller).
     */
    public function register(Request $request)
    {
        /** @var PosProfile $profile */
        $profile = $request->attributes->get('posProfile');
        $setting = Setting::current();

        // Only show leaf categories that actually contain sellable items
        // (hide empty groups to avoid wasting space on the register). With
        // "Hide Unavailable Items" on, a category whose items are all
        // out-of-stock is empty too and must not show.
        $groups = ItemGroup::where('is_group', false)
            ->whereHas('items', function ($q) use ($profile) {
                $q->where('disabled', false);
                if ($filter = $this->stock->availableItemsFilter($profile)) {
                    $q->where($filter);
                }
            })
            ->orderBy('name')
            ->get(['id', 'name']);
        $currentShift = $this->shifts->currentOpenShift($request->user());

        // If the profile requires a shift and the cashier has none, kick them out.
        if ($profile->require_shift && (! $currentShift || $currentShift->pos_profile_id !== $profile->id)) {
            return redirect()
                ->route('shifts.open.form')
                ->with('warning', "Open a shift on {$profile->name} before selling.");
        }

        // An ERPNext profile's catalog is pulled when the shift opens; wait for it.
        if (! $this->catalog->isReady($profile)) {
            return redirect()->route('pos.preparing');
        }

        // Bootstrap the catalog with the first 60 items so the page renders
        // useful content without a follow-up fetch. The JS layer can paginate
        // or search via /pos/items for larger catalogs. Profiles with ERPNext's
        // "Hide Unavailable Items" checked stop selling items with no stock.
        $items = $this->stock->hideUnavailable(Item::with(['group', 'stockUom', 'salesUom', 'barcodes', 'prices'])->available(), $profile)
            ->orderBy('item_name')
            ->limit(60)
            ->get();
        $stockMap = $this->stock->balancesFor($items->pluck('id')->all(), $profile->warehouse_id);

        // Default taxes — the register applies the profile's own ERPNext
        // "Taxes and Charges" template to each line. Profiles with no template
        // get no default tax (ERPNext's own behaviour: no template on the
        // profile = no tax on the sale).
        $defaultTaxes = $profile->taxTemplate()
            ? Tax::where('erpnext_id', $profile->taxTemplate())
                ->get(['id', 'name', 'rate', 'included_in_price'])
            : collect();

        $draft = $request->query('draft')
            ? PosDraft::with('customer')->where('pos_profile_id', $profile->id)->find($request->query('draft'))
            : null;
        if ($request->query('draft') && ! $draft) {
            return redirect()->route('pos.register')->with('warning', 'That draft no longer exists.');
        }

        return view('pos.register', [
            'draftCart' => $draft ? $this->draftCart($draft, $profile) : null,
            'draftCount' => PosDraft::where('pos_profile_id', $profile->id)->count(),
            'profile' => $profile,
            'setting' => $setting,
            'groups' => $groups,
            'items' => $items,
            'stockMap' => $stockMap,
            'defaultTaxes' => $defaultTaxes,
            'currentShift' => $currentShift,
        ]);
    }

    /**
     * Turn a saved draft back into register state. Item names, units and
     * stock come from today's catalog and prices are today's price list, so a
     * stale draft can never charge an old price (a hand-set price is kept only
     * when the profile allows price changes). Items that are no longer
     * sellable are dropped.
     *
     * @return array<string, mixed>
     */
    protected function draftCart(PosDraft $draft, PosProfile $profile): array
    {
        $payload = $draft->payload;
        $saved = collect($payload['items'] ?? []);
        $items = $this->stock->hideUnavailable(Item::with(['group', 'stockUom', 'salesUom'])->available(), $profile)
            ->whereIn('id', $saved->pluck('item_id'))
            ->get()
            ->keyBy('id');
        $stockMap = $this->stock->balancesFor($items->keys()->all(), $profile->warehouse_id);

        $lines = $saved->filter(fn ($l) => $items->has($l['item_id']))->map(function ($l) use ($items, $stockMap, $profile) {
            $item = $items[$l['item_id']];
            $listRate = (float) $item->effectiveRate($profile->selling_price_list);

            return [
                'item' => [
                    'id' => $item->id,
                    'item_code' => $item->item_code,
                    'item_name' => $item->item_name,
                    'item_group_id' => $item->item_group_id,
                    'item_group' => $item->group?->name,
                    'uom' => $item->salesUom?->uom_name ?? $item->stockUom?->uom_name ?? 'Unit',
                    'rate' => $listRate,
                    'allow_discount' => (bool) $item->allow_discount,
                    'is_stock_item' => (bool) $item->is_stock_item,
                    'stock_qty' => (float) ($stockMap[$item->id] ?? 0),
                ],
                'qty' => (float) $l['qty'],
                'rate' => ($profile->allow_rate_change && isset($l['rate'])) ? (float) $l['rate'] : $listRate,
                'discount_type' => $l['discount_type'] ?? 'percentage',
                'discount_value' => (float) ($l['discount_value'] ?? 0),
            ];
        })->values()->all();

        return [
            'id' => $draft->id,
            'label' => $draft->label,
            'customer' => $draft->customer ? ['id' => $draft->customer->id, 'name' => $draft->customer->customer_name] : null,
            'coupon_code' => $payload['coupon_code'] ?? null,
            'additional_discount_type' => $payload['additional_discount_type'] ?? 'percentage',
            'additional_discount_value' => (float) ($payload['additional_discount_value'] ?? 0),
            'is_credit' => (bool) ($payload['is_credit'] ?? false),
            'credit_days' => $payload['credit_days'] ?? null,
            'due_date' => $payload['due_date'] ?? null,
            'lines' => $lines,
            'dropped' => $saved->count() - count($lines),
        ];
    }

    /** GET /pos/preparing — progress screen while the profile's catalog is pulled. */
    public function preparing(Request $request)
    {
        $profile = $request->attributes->get('posProfile');
        if ($this->catalog->isReady($profile)) {
            return redirect()->route('pos.register');
        }

        return view('pos.prepare', ['profile' => $profile, 'progress' => $this->catalog->progress($profile)]);
    }

    /** GET /pos/preparing/status — polled by the progress screen. */
    public function preparingStatus(Request $request)
    {
        return response()->json(['data' => $this->catalog->progress($request->attributes->get('posProfile'))]);
    }

    /** POST /pos/preparing/retry */
    public function preparingRetry(Request $request)
    {
        SyncProfileCatalog::dispatch($request->attributes->get('posProfile')->id);

        return redirect()->route('pos.preparing')->with('info', 'Sync queued again.');
    }

    /**
     * GET /pos/items — JSON item grid for search/pagination.
     */
    public function items(Request $request)
    {
        $profile = $request->attributes->get('posProfile');
        $pageLength = min((int) $request->query('limit_page_length', 60), 500);
        $offset = max(0, (int) $request->query('limit_start', 0));

        $query = $this->stock->hideUnavailable(Item::with(['group', 'stockUom', 'salesUom', 'barcodes', 'prices'])->available(), $profile);
        if ($q = $request->query('q')) {
            $query->search($q);
        }
        if ($groupId = $request->query('item_group_id')) {
            $query->where('item_group_id', $groupId);
        }

        $total = (clone $query)->toBase()->count();
        $items = $query->orderBy('item_name')->offset($offset)->limit($pageLength)->get();
        $stockMap = $this->stock->balancesFor($items->pluck('id')->all(), $profile->warehouse_id);
        $priceList = $profile->selling_price_list;

        return response()->json([
            'data' => $items->map(fn (Item $item) => [
                'id' => $item->id,
                'item_code' => $item->item_code,
                'item_name' => $item->item_name,
                'item_group_id' => $item->item_group_id,
                'uom' => $item->salesUom?->uom_name ?? $item->stockUom?->uom_name ?? 'Unit',
                'rate' => $item->effectiveRate($priceList),
                'allow_discount' => $item->allow_discount,
                'is_stock_item' => $item->is_stock_item,
                'stock_qty' => $stockMap[$item->id] ?? 0.0,
            ]),
            'meta' => ['total' => $total],
        ]);
    }

    /**
     * GET /pos/barcode/{code} — scanner entry.
     */
    public function itemByBarcode(Request $request, string $code)
    {
        $barcode = ItemBarcode::with('item')->where('barcode', $code)->first();
        if (! $barcode || ! $barcode->item || $barcode->item->disabled) {
            return response()->json(['data' => null], 404);
        }
        $profile = $request->attributes->get('posProfile');
        $item = $barcode->item;
        $stockMap = $this->stock->balancesFor([$item->id], $profile->warehouse_id);

        return response()->json([
            'data' => [
                'id' => $item->id,
                'item_code' => $item->item_code,
                'item_name' => $item->item_name,
                'uom' => $item->salesUom?->uom_name ?? $item->stockUom?->uom_name ?? 'Unit',
                'rate' => $item->effectiveRate($profile->selling_price_list),
                'allow_discount' => $item->allow_discount,
                'is_stock_item' => $item->is_stock_item,
                'stock_qty' => $stockMap[$item->id] ?? 0.0,
            ],
        ]);
    }

    /**
     * POST /pos/invoice — checkout. Calls InvoiceService::create() exactly
     * like the API does. The cart payload is built client-side and posted as
     * a normal form-encoded JSON body.
     */
    public function checkout(Request $request)
    {
        $profile = $request->attributes->get('posProfile');
        $shift = $this->shifts->currentOpenShift($request->user());

        $data = $request->validate([
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'coupon_code' => ['nullable', 'string', 'max:32'],
            'additional_discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'additional_discount_amount' => ['nullable', 'numeric', 'min:0'],
            'loyalty_points' => ['nullable', 'integer', 'min:0'],
            'remarks' => ['nullable', 'string', 'max:1000'],
            'draft_id' => ['nullable', 'integer'],
            'is_credit' => ['nullable', 'boolean'],
            'credit_days' => ['nullable', 'integer', Rule::in(Invoice::CREDIT_TERM_DAYS)],
            'due_date' => ['nullable', 'date'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.item_id' => ['required', 'integer', 'exists:items,id'],
            'items.*.qty' => ['required', 'numeric', 'not_in:0'],
            'items.*.uom' => ['nullable', 'string', 'max:64'],
            'items.*.rate' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.discount_amount' => ['nullable', 'numeric', 'min:0'],
            'items.*.tax_id' => ['nullable', 'integer', 'exists:taxes,id'],
            'payments' => ['nullable', 'array'],
            'payments.*.mode_of_payment' => ['required', 'string', 'max:64'],
            'payments.*.amount' => ['required', 'numeric'],
            'payments.*.reference_no' => ['nullable', 'string', 'max:64'],
        ]);

        // Attach profile + shift context. offline_id gives idempotency for the
        // "did the network drop after I clicked Pay?" case.
        $data['pos_profile_id'] = $profile->id;
        $data['shift_id'] = $shift?->id;
        $data['offline_id'] ??= (string) Str::uuid();

        try {
            $result = $this->invoices->create($data, $request->user());
            $invoice = $result['invoice'];

            // A charged draft is finished: remove it so it cannot be charged twice.
            if (! empty($data['draft_id'])) {
                PosDraft::where('pos_profile_id', $profile->id)->whereKey($data['draft_id'])->delete();
            }

            return response()->json([
                'data' => [
                    'invoice_id' => $invoice->id,
                    'invoice_no' => $invoice->invoice_no,
                    'duplicate' => $result['duplicate'],
                ],
            ], $result['duplicate'] ? 200 : 201);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Checkout failed.',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    /**
     * GET /pos/receipt/{invoice} — printable receipt.
     */
    public function receipt(Request $request, Invoice $invoice)
    {
        $invoice->load(['items', 'payments', 'taxes', 'customer', 'cashier', 'posProfile']);
        $setting = Setting::current();

        return view('pos.receipt', [
            'invoice' => $invoice,
            'setting' => $setting,
        ]);
    }
}
