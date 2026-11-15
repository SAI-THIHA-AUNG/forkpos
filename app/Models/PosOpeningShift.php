<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosOpeningShift extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    protected $fillable = [
        'shift_no', 'user_id', 'pos_profile_id', 'status',
        'opening_note', 'closing_note', 'started_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function posProfile()
    {
        return $this->belongsTo(PosProfile::class);
    }

    public function balances()
    {
        return $this->hasMany(PosOpeningShiftBalance::class, 'opening_shift_id');
    }

    public function cashMovements()
    {
        return $this->hasMany(PosCashMovement::class, 'shift_id');
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class, 'shift_id');
    }

    public function closingShift()
    {
        return $this->hasOne(PosClosingShift::class, 'opening_shift_id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }
}
