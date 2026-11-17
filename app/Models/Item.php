<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Item extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'item_code', 'item_name', 'item_group_id', 'description', 'image_url',
        'stock_uom_id', 'sales_uom_id', 'is_stock_item', 'has_variants',
        'variant_of_id', 'standard_rate', 'allow_discount',
        'min_order_qty', 'max_order_qty', 'brand', 'weight', 'disabled', 'erpnext_id',
    ];

    protected function casts(): array
    {
        return [
            'standard_rate' => 'float',
            'allow_discount' => 'boolean',
            'is_stock_item' => 'boolean',
            'has_variants' => 'boolean',
            'disabled' => 'boolean',
            'min_order_qty' => 'float',
            'max_order_qty' => 'float',
            'weight' => 'float',
        ];
    }

    public function group()
    {
        return $this->belongsTo(ItemGroup::class, 'item_group_id');
    }

    public function stockUom()
    {
        return $this->belongsTo(Uom::class, 'stock_uom_id');
    }

    public function salesUom()
    {
        return $this->belongsTo(Uom::class, 'sales_uom_id');
    }

    public function prices()
    {
        return $this->hasMany(ItemPrice::class);
    }

    public function barcodes()
    {
        return $this->hasMany(ItemBarcode::class);
    }

    public function uomConversions()
    {
        return $this->hasMany(ItemUomConversion::class);
    }

    public function stockBalances()
    {
        return $this->hasMany(StockBalance::class);
    }

    /**
     * Effective selling rate for a price list (falls back to standard_rate).
     * A null price list (profile without one) means: use standard_rate.
     */
    public function effectiveRate(?string $priceList = null, ?int $customerId = null): float
    {
        if ($priceList === null || $priceList === '') {
            return (float) $this->standard_rate;
        }

        $price = $this->prices()
            ->where('price_list', $priceList)
            ->whereNull('customer_id')
            ->where(function ($q) {
                $q->whereNull('valid_from')->orWhere('valid_from', '<=', now()->toDateString());
            })
            ->where(function ($q) {
                $q->whereNull('valid_to')->orWhere('valid_to', '>=', now()->toDateString());
            })
            ->orderByDesc('customer_id')
            ->first();

        if ($customerId) {
            $customerPrice = $this->prices()
                ->where('price_list', $priceList)
                ->where('customer_id', $customerId)
                ->first();
            if ($customerPrice) {
                $price = $customerPrice;
            }
        }

        return $price ? (float) $price->price_list_rate : (float) $this->standard_rate;
    }

    public function stockQty(?int $warehouseId = null): float
    {
        $q = $this->stockBalances();
        if ($warehouseId) {
            $q->where('warehouse_id', $warehouseId);
        }

        return (float) $q->sum('qty');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('item_name', 'like', "%{$term}%")
                ->orWhere('item_code', 'like', "%{$term}%")
                ->orWhere('brand', 'like', "%{$term}%")
                ->orWhereIn('id', function ($sub) use ($term) {
                    $sub->select('item_id')
                        ->from('item_barcodes')
                        ->where('barcode', $term);
                });
        });
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('disabled', false);
    }
}
