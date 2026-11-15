<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PAID = 'paid';

    public const STATUS_PARTLY_PAID = 'partly_paid';

    public const STATUS_UNPAID = 'unpaid';

    public const STATUS_RETURNED = 'returned';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'invoice_no', 'customer_id', 'pos_profile_id', 'shift_id', 'user_id', 'status',
        'posting_datetime', 'posting_date', 'is_return', 'return_against_id',
        'total_qty', 'net_total', 'tax_total', 'discount_total', 'grand_total',
        'rounded_total', 'paid_amount', 'change_amount', 'outstanding_amount',
        'currency', 'price_list', 'coupon_code', 'coupon_discount',
        'loyalty_points_earned', 'loyalty_points_spent', 'offline_id', 'remarks',
        'erpnext_id', 'sync_status',
    ];

    protected function casts(): array
    {
        return [
            'posting_datetime' => 'datetime',
            'posting_date' => 'date',
            'is_return' => 'boolean',
            'total_qty' => 'float',
            'net_total' => 'float',
            'tax_total' => 'float',
            'discount_total' => 'float',
            'grand_total' => 'float',
            'rounded_total' => 'float',
            'paid_amount' => 'float',
            'change_amount' => 'float',
            'outstanding_amount' => 'float',
            'coupon_discount' => 'float',
            'loyalty_points_earned' => 'integer',
            'loyalty_points_spent' => 'integer',
        ];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function posProfile()
    {
        return $this->belongsTo(PosProfile::class);
    }

    public function shift()
    {
        return $this->belongsTo(PosOpeningShift::class, 'shift_id');
    }

    public function cashier()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function items()
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments()
    {
        return $this->hasMany(InvoicePayment::class);
    }

    public function taxes()
    {
        return $this->hasMany(InvoiceTax::class);
    }

    public function returnAgainst()
    {
        return $this->belongsTo(self::class, 'return_against_id');
    }

    public function returns()
    {
        return $this->hasMany(self::class, 'return_against_id');
    }

    public function isReturn(): bool
    {
        return $this->is_return;
    }
}
