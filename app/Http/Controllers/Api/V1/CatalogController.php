<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Item;
use App\Models\ItemGroup;
use App\Models\Setting;
use App\Models\Uom;
use App\Models\Warehouse;
use App\Services\StockService;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    /**
     * GET /api/v1/items — the POS product grid.
     *
     * Query: q (search), item_group_id, price_list, warehouse_id,
     *        limit_start, limit_page_length (Frappe-style pagination), with_stock
     */
    public function items(Request $request, StockService $stock)
    {
        $setting = Setting::current();
        $pageLength = min((int) $request->query('limit_page_length', 50), 500);
        $offset = max(0, (int) $request->query('limit_start', 0));

        $query = Item::with(['group', 'stockUom', 'salesUom', 'barcodes', 'prices'])
            ->available()
            ->withTrashed(false);

        if ($q = $request->query('q')) {
            $query->search($q);
        }
        if ($groupId = $request->query('item_group_id')) {
            $query->where('item_group_id', $groupId);
        }

        $total = (clone $query)->toBase()->count();
        $items = $query->orderBy('item_name')
            ->offset($offset)
            ->limit($pageLength)
            ->get();

        $priceList = $request->query('price_list', 'Standard Selling');
        $warehouseId = $request->query('warehouse_id') ? (int) $request->query('warehouse_id') : null;
        $withStock = $request->query('with_stock', true) !== 'false';

        $stockMap = $withStock
            ? $stock->balancesFor($items->pluck('id')->all(), $warehouseId)
            : [];

        return response()->json([
            'data' => $items->map(fn (Item $item) => $this->itemPayload($item, $priceList, $stockMap)),
            'meta' => [
                'total' => $total,
                'limit_start' => $offset,
                'limit_page_length' => $pageLength,
                'currency' => $setting->currency,
            ],
        ]);
    }

    /**
     * GET /api/v1/items/{id}
     */
    public function item(Request $request, int $id, StockService $stock)
    {
        $item = Item::with(['group', 'stockUom', 'salesUom', 'barcodes', 'prices', 'uomConversions'])
            ->available()
            ->findOrFail($id);

        $priceList = $request->query('price_list', 'Standard Selling');
        $warehouseId = $request->query('warehouse_id') ? (int) $request->query('warehouse_id') : null;
        $stockMap = $stock->balancesFor([$item->id], $warehouseId);

        return response()->json(['data' => $this->itemPayload($item, $priceList, $stockMap, detailed: true)]);
    }

    /**
     * GET /api/v1/items/barcode/{code} — scanner entry point.
     */
    public function itemByBarcode(Request $request, string $code, StockService $stock)
    {
        $barcode = \App\Models\ItemBarcode::with('item')
            ->where('barcode', $code)
            ->firstOrFail();
        $item = $barcode->item;
        abort_if($item === null || $item->disabled || $item->trashed(), 404, 'Item not found or disabled.');
        $item->load(['group', 'stockUom', 'salesUom', 'barcodes', 'prices']);

        $priceList = $request->query('price_list', 'Standard Selling');
        $warehouseId = $request->query('warehouse_id') ? (int) $request->query('warehouse_id') : null;
        $stockMap = $stock->balancesFor([$item->id], $warehouseId);

        return response()->json(['data' => $this->itemPayload($item, $priceList, $stockMap)]);
    }

    /**
     * GET /api/v1/item-groups
     */
    public function itemGroups()
    {
        return response()->json([
            'data' => ItemGroup::orderBy('name')->get(['id', 'name', 'parent_id', 'is_group']),
        ]);
    }

    /**
     * GET /api/v1/uoms
     */
    public function uoms()
    {
        return response()->json([
            'data' => Uom::orderBy('uom_name')->get(['id', 'uom_name', 'must_be_whole_number']),
        ]);
    }

    /**
     * GET /api/v1/warehouses
     */
    public function warehouses()
    {
        return response()->json([
            'data' => Warehouse::orderBy('name')->get(['id', 'name', 'warehouse_code', 'is_group', 'is_default']),
        ]);
    }

    /**
     * GET /api/v1/price-lists — distinct price lists known to the system.
     */
    public function priceLists()
    {
        $lists = \App\Models\ItemPrice::distinct()->orderBy('price_list')->pluck('price_list')
            ->push('Standard Selling')->unique()->values();

        return response()->json(['data' => $lists]);
    }

    protected function itemPayload(Item $item, string $priceList, array $stockMap, bool $detailed = false): array
    {
        $payload = [
            'id' => $item->id,
            'item_code' => $item->item_code,
            'item_name' => $item->item_name,
            'item_group' => $item->group?->name,
            'item_group_id' => $item->item_group_id,
            'description' => $item->description,
            'image_url' => $item->image_url,
            'stock_uom' => $item->stockUom?->uom_name,
            'sales_uom' => $item->salesUom?->uom_name ?? $item->stockUom?->uom_name,
            'is_stock_item' => $item->is_stock_item,
            'standard_rate' => (float) $item->standard_rate,
            'rate' => $item->effectiveRate($priceList), // effective selling rate for the price list
            'price_list' => $priceList,
            'allow_discount' => $item->allow_discount,
            'brand' => $item->brand,
            'disabled' => $item->disabled,
            'barcodes' => $item->barcodes->pluck('barcode'),
            'stock_qty' => $stockMap[$item->id] ?? 0.0,
            'has_stock' => ($stockMap[$item->id] ?? 0) > 0 || ! $item->is_stock_item,
        ];

        if ($detailed) {
            $payload['uom_conversions'] = $item->uomConversions->map(fn ($c) => [
                'uom' => \App\Models\Uom::find($c->uom_id)?->uom_name,
                'conversion_factor' => (float) $c->conversion_factor,
            ])->filter()->values();
            $payload['prices'] = $item->prices->map(fn ($p) => [
                'price_list' => $p->price_list,
                'rate' => (float) $p->price_list_rate,
                'currency' => $p->currency,
            ]);
        }

        return $payload;
    }
}
