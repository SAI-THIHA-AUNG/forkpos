<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosClosingShiftPayment extends Model
{
    protected $fillable = [
        'closing_shift_id', 'mode_of_payment', 'opening_amount', 'collected_amount',
        'cash_in_amount', 'cash_out_amount', 'expected_amount', 'counted_amount', 'difference',
    ];

    protected function casts(): array
    {
        return [
            'opening_amount' => 'float',
            'collected_amount' => 'float',
            'cash_in_amount' => 'float',
            'cash_out_amount' => 'float',
            'expected_amount' => 'float',
            'counted_amount' => 'float',
            'difference' => 'float',
        ];
    }

    public function closingShift()
    {
        return $this->belongsTo(PosClosingShift::class, 'closing_shift_id');
    }
}
