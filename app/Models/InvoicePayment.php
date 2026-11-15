<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoicePayment extends Model
{
    protected $fillable = ['invoice_id', 'mode_of_payment', 'amount', 'reference_no', 'reference_date'];

    protected function casts(): array
    {
        return ['amount' => 'float', 'reference_date' => 'date'];
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
