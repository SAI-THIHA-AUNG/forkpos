<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosClosingShiftTax extends Model
{
    protected $fillable = ['closing_shift_id', 'tax_id', 'description', 'rate', 'amount'];

    protected function casts(): array
    {
        return ['rate' => 'float', 'amount' => 'float'];
    }

    public function closingShift()
    {
        return $this->belongsTo(PosClosingShift::class, 'closing_shift_id');
    }
}
