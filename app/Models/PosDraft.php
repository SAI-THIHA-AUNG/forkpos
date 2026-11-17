<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A saved ("held") register cart. It has no invoice number, moves no stock and
 * takes no payment: it only remembers the cart until someone resumes and charges it.
 */
class PosDraft extends Model
{
    protected $fillable = [
        'pos_profile_id', 'user_id', 'customer_id', 'label', 'item_count', 'total', 'payload',
    ];

    protected function casts(): array
    {
        return [
            'item_count' => 'integer',
            'total' => 'float',
            'payload' => 'array',
        ];
    }

    public function posProfile()
    {
        return $this->belongsTo(PosProfile::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
