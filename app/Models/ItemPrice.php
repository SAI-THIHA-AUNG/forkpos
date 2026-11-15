<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ItemPrice extends Model
{
    protected $fillable = [
        'item_id', 'price_list', 'currency', 'price_list_rate',
        'uom_id', 'customer_id', 'valid_from', 'valid_to',
    ];

    protected function casts(): array
    {
        return [
            'price_list_rate' => 'float',
            'valid_from' => 'date',
            'valid_to' => 'date',
        ];
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }
}
