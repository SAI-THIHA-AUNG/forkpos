<?php

namespace App\Services;

use App\Models\Item;
use App\Models\PosProfile;
use App\Models\StockBalance;
use App\Models\StockLedgerEntry;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockService
{
    /**
     * Post a stock movement and update the item/warehouse balance.
     *
     * @param  int|null  $warehouseId  falls back to the default warehouse
     * @param  bool  $allowNegative  profile/setting policy
     */
    public function postMovement(
        Item $item,
        ?int $warehouseId,
        float $qtyChange,
        string $refType,
        ?string $refId,
        bool $allowNegative = false,
    ): void {
        $warehouseId ??= Warehouse::where('is_default', true)->value('id');
        if (! $warehouseId) {
            return; // no warehouse configured — stock tracking disabled
        }

        if ($qtyChange == 0.0) {
            return;
        }

        $balance = StockBalance::firstOrNew([
            'item_id' => $item->id,
            'warehouse_id' => $warehouseId,
        ]);
        $before = (float) ($balance->qty ?? 0);
        $after = round($before + $qtyChange, 6);

        if ($after < 0 && ! $allowNegative) {
            throw ValidationException::withMessages([
                'items' => [
                    "Insufficient stock for {$item->item_name} (available: {$before}). "
                    .'Negative stock is disabled for this POS profile.',
                ],
            ]);
        }

        $balance->qty = $after;
        $balance->save();

        StockLedgerEntry::create([
            'item_id' => $item->id,
            'warehouse_id' => $warehouseId,
            'qty_change' => round($qtyChange, 6),
            'qty_after' => $after,
            'ref_type' => $refType,
            'ref_id' => $refId,
            'posting_date' => now()->toDateString(),
        ]);
    }

    /**
     * Set an absolute stock qty (used by the ERPNext sync / opening stock).
     */
    public function setBalance(Item $item, int $warehouseId, float $qty, bool $ledger = true): void
    {
        $balance = StockBalance::firstOrNew([
            'item_id' => $item->id,
            'warehouse_id' => $warehouseId,
        ]);
        $before = (float) ($balance->qty ?? 0);
        $balance->qty = round($qty, 6);
        $balance->save();

        if ($ledger) {
            StockLedgerEntry::create([
                'item_id' => $item->id,
                'warehouse_id' => $warehouseId,
                'qty_change' => round($qty - $before, 6),
                'qty_after' => round($qty, 6),
                'ref_type' => 'sync',
                'ref_id' => null,
                'posting_date' => now()->toDateString(),
            ]);
        }
    }

    /**
     * The item constraint for the profile's ERPNext "Hide Unavailable Items"
     * policy, or null when it does not apply (flag off, or no warehouse).
     * Stock items with non-positive stock in the profile's warehouse are
     * excluded; non-stock items (services) are always sellable. Reused by the
     * register's item query and its category list so an item group with no
     * sellable items never shows.
     *
     * @return (\Closure(Builder): void)|null
     */
    public function availableItemsFilter(PosProfile $profile): ?\Closure
    {
        if (! $profile->hideUnavailableItems() || ! $profile->warehouse_id) {
            return null;
        }

        $inStock = DB::table('stock_balances')
            ->where('warehouse_id', $profile->warehouse_id)
            ->where('qty', '>', 0)
            ->pluck('item_id')
            ->all();

        return function (Builder $query) use ($inStock): void {
            $query->where(function (Builder $scope) use ($inStock): void {
                $scope->where('is_stock_item', false)->orWhereIn('id', $inStock);
            });
        };
    }

    /**
     * Apply the profile's ERPNext "Hide Unavailable Items" policy to a
     * register item query: stock items with non-positive stock in the
     * profile's warehouse are not displayed. Non-stock items (services) are
     * always sellable, and a profile without a warehouse cannot evaluate the
     * policy, so the list stays untouched in both cases.
     */
    public function hideUnavailable(Builder $query, PosProfile $profile): Builder
    {
        $filter = $this->availableItemsFilter($profile);

        return $filter ? $query->where($filter) : $query;
    }

    /**
     * Stock qty map for a set of items (single query, avoids N+1 on the POS screen).
     *
     * @param  array<int, int>  $itemIds
     * @return array<int, float> item_id => qty (in the given warehouse, or total)
     */
    public function balancesFor(array $itemIds, ?int $warehouseId = null): array
    {
        if (empty($itemIds)) {
            return [];
        }

        $query = DB::table('stock_balances')->whereIn('item_id', $itemIds);
        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        $map = [];
        foreach ($query->get() as $row) {
            $map[(int) $row->item_id] = ($map[(int) $row->item_id] ?? 0) + (float) $row->qty;
        }

        return $map;
    }
}
