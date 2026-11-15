<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceTax extends Model
{
    protected $fillable = ['invoice_id', 'tax_id', 'description', 'rate', 'amount', 'included_in_price'];

    protected function casts(): array
    {
        return ['rate' => 'float', 'amount' => 'float', 'included_in_price' => 'boolean'];
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }
}
