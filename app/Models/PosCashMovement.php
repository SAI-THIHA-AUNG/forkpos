<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosCashMovement extends Model
{
    public const TYPE_IN = 'in';

    public const TYPE_OUT = 'out';

    protected $fillable = ['shift_id', 'user_id', 'type', 'mode_of_payment', 'amount', 'reason'];

    protected function casts(): array
    {
        return ['amount' => 'float'];
    }

    public function shift()
    {
        return $this->belongsTo(PosOpeningShift::class, 'shift_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
