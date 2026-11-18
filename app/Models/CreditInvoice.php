<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An unpaid (or part-paid) credit Sales Invoice — from ANY van. Rows are filled
 * by the ERPNext pull and by this van's own credit sales, so a van can collect
 * on an invoice another van created.
 *
 * outstanding = what ERPNext last reported − collections we took that have not
 * reached ERPNext yet. Once a collection is pushed, `erp_outstanding` is lowered
 * by the same amount, so it is never subtracted twice.
 */
class CreditInvoice extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_SETTLED = 'settled';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'erpnext_id', 'invoice_id', 'customer_id', 'invoice_no', 'van_code', 'origin',
        'posting_date', 'due_date', 'currency', 'grand_total', 'paid_amount', 'erp_outstanding',
        'status', 'items', 'erp_modified', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'posting_date' => 'date',
            'due_date' => 'date',
            'grand_total' => 'float',
            'paid_amount' => 'float',
            'erp_outstanding' => 'float',
            'items' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function collections()
    {
        return $this->hasMany(Collection::class)->orderBy('id');
    }

    /** Adds `pending_collected` (collections not yet in ERPNext) to each row — avoids N+1 in lists. */
    public function scopeWithPending(Builder $query): Builder
    {
        return $query->withSum(['collections as pending_collected' => fn ($q) => $q->where('sync_status', '!=', Collection::SYNCED)], 'amount');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    public function getPendingCollectedAttribute($value): float
    {
        if ($value !== null) {
            return (float) $value;
        }

        return (float) $this->collections()->where('sync_status', '!=', Collection::SYNCED)->sum('amount');
    }

    public function getOutstandingAttribute(): float
    {
        return round(max(0.0, (float) $this->erp_outstanding - $this->pending_collected), 6);
    }

    public function isOverdue(): bool
    {
        return $this->status === self::STATUS_OPEN
            && $this->due_date !== null
            && $this->due_date->lt(now()->startOfDay())
            && $this->outstanding > 0.009;
    }
}
