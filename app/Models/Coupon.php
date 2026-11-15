<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    public const TYPE_PERCENT = 'percent';

    public const TYPE_AMOUNT = 'amount';

    protected $fillable = [
        'coupon_code', 'type', 'discount_value', 'min_order_amount',
        'max_use', 'used_count', 'valid_from', 'valid_to', 'is_active', 'customer_id',
    ];

    protected function casts(): array
    {
        return [
            'discount_value' => 'float',
            'min_order_amount' => 'float',
            'max_use' => 'integer',
            'used_count' => 'integer',
            'valid_from' => 'date',
            'valid_to' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
