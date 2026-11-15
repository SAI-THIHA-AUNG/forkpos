<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Invoice;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\Setting;
use App\Services\InvoiceService;
use App\Services\ShiftService;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PosController extends Controller
{
    public function __construct(
        protected InvoiceService $invoices,
        protected ShiftService $shifts,
        protected StockService $stock,
    ) {}

    /**
     * GET /pos — the register screen.
     * Renders the product grid + cart shell; the JS layer fetches items via
     * /pos/items and posts checkout via /pos/invoice (same controller).
     */
    public function register(Request $request)
    {
        /** @var \App\Models\PosProfile $profile */
        $profile = $request->attributes->get('posProfile');
        $setting = Setting::current();

        $groups = ItemGroup::where('is_group', false)
            ->orderBy('name')
            ->get(['id', 'name']);
        $currentShift = $this->shifts->currentOpenShift($request->user());

        // If the profile requires a shift and the cashier has none, kick them out.
        if ($profile->require_shift && (! $currentShift || $currentShift->pos_profile_id !== $profile->id)) {
            return redirect()
                ->route('shifts.open.form')
                ->with('warning', "Open a shift on {$profile->name} before selling.");
        }

        // Bootstrap the catalog with the first 60 items so the page renders
        // useful content without a follow-up fetch. The JS layer can paginate
        // or search via /pos/items for larger catalogs.
        $items = Item::with(['group', 'stockUom', 'salesUom', 'barcodes', 'prices'])
            ->available()
            ->orderBy('item_name')
            ->limit(60)
            ->get();
        $stockMap = $this->stock->balancesFor($items->pluck('id')->all(), $profile->warehouse_id);

        // Default taxes — the register applies the default tax template to
        // each line unless the client overrides per line.
        $defaultTaxes = \App\Models\Tax::where('is_default', true)->get(['id', 'name', 'rate', 'included_in_price']);

        return view('pos.register', [
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
     * GET /pos/items — JSON item grid for search/pagination.
     */
    public function items(Request $request)
    {
        $profile = $request->attributes->get('posProfile');
        $pageLength = min((int) $request->query('limit_page_length', 60), 500);
        $offset = max(0, (int) $request->query('limit_start', 0));

        $query = Item::with(['group', 'stockUom', 'salesUom', 'barcodes', 'prices'])->available();
        if ($q = $request->query('q')) {
            $query->search($q);
        }
        if ($groupId = $request->query('item_group_id')) {
            $query->where('item_group_id', $groupId);
        }

        $total = (clone $query)->toBase()->count();
        $items = $query->orderBy('item_name')->offset($offset)->limit($pageLength)->get();
        $stockMap = $this->stock->balancesFor($items->pluck('id')->all(), $profile->warehouse_id);
        $priceList = $profile->selling_price_list ?: 'Standard Selling';

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
        $barcode = \App\Models\ItemBarcode::with('item')->where('barcode', $code)->first();
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
                'rate' => $item->effectiveRate($profile->selling_price_list ?: 'Standard Selling'),
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
            'loyalty_points' => ['nullable', 'integer', 'min:0'],
            'remarks' => ['nullable', 'string', 'max:1000'],
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
