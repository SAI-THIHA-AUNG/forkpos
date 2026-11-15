<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosClosingShift extends Model
{
    protected $fillable = [
        'closing_no', 'opening_shift_id', 'user_id', 'pos_profile_id',
        'period_start', 'period_end', 'grand_total', 'net_total', 'total_quantity', 'note',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'grand_total' => 'float',
            'net_total' => 'float',
            'total_quantity' => 'float',
        ];
    }

    public function openingShift()
    {
        return $this->belongsTo(PosOpeningShift::class, 'opening_shift_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function posProfile()
    {
        return $this->belongsTo(PosProfile::class);
    }

    public function payments()
    {
        return $this->hasMany(PosClosingShiftPayment::class, 'closing_shift_id');
    }

    public function taxes()
    {
        return $this->hasMany(PosClosingShiftTax::class, 'closing_shift_id');
    }
}
