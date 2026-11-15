<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id', 'item_id', 'item_code', 'item_name', 'uom', 'conversion_factor',
        'qty', 'price_list_rate', 'rate', 'discount_percentage', 'discount_amount',
        'amount', 'tax_id', 'tax_rate', 'tax_amount', 'tax_included',
        'warehouse_id', 'batch_no', 'serial_no', 'note',
    ];

    protected function casts(): array
    {
        return [
            'conversion_factor' => 'float',
            'qty' => 'float',
            'price_list_rate' => 'float',
            'rate' => 'float',
            'discount_percentage' => 'float',
            'discount_amount' => 'float',
            'amount' => 'float',
            'tax_rate' => 'float',
            'tax_amount' => 'float',
            'tax_included' => 'boolean',
        ];
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function tax()
    {
        return $this->belongsTo(Tax::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }
}
