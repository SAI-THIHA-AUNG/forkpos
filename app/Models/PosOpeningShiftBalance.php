<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosOpeningShiftBalance extends Model
{
    protected $fillable = ['opening_shift_id', 'mode_of_payment', 'opening_amount'];

    protected function casts(): array
    {
        return ['opening_amount' => 'float'];
    }

    public function shift()
    {
        return $this->belongsTo(PosOpeningShift::class, 'opening_shift_id');
    }
}
