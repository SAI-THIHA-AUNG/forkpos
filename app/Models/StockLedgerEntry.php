<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StockLedgerEntry extends Model
{
    protected $fillable = [
        'item_id', 'warehouse_id', 'qty_change', 'qty_after',
        'ref_type', 'ref_id', 'posting_date',
    ];

    protected function casts(): array
    {
        return [
            'qty_change' => 'float',
            'qty_after' => 'float',
            'posting_date' => 'date',
        ];
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }
}
