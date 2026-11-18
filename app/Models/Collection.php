<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Money collected against a credit invoice; pushed to ERPNext as a Payment Entry. */
class Collection extends Model
{
    public const PENDING = 'pending';

    public const SYNCED = 'synced';

    public const FAILED = 'failed';

    protected $fillable = [
        'collection_no', 'credit_invoice_id', 'customer_id', 'pos_profile_id', 'user_id', 'shift_id',
        'van_code', 'mode_of_payment', 'amount', 'reference_no', 'reference_date', 'remarks',
        'posting_datetime', 'posting_date', 'offline_id', 'erpnext_id', 'sync_status', 'sync_error',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'reference_date' => 'date',
            'posting_datetime' => 'datetime',
            'posting_date' => 'date',
        ];
    }

    public function creditInvoice()
    {
        return $this->belongsTo(CreditInvoice::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function cashier()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function posProfile()
    {
        return $this->belongsTo(PosProfile::class);
    }
}
