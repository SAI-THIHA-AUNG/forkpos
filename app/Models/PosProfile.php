<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosProfile extends Model
{
    protected $fillable = [
        'name', 'company', 'currency', 'warehouse_id', 'selling_price_list',
        'allow_rate_change', 'allow_discount_change', 'allow_negative_stock',
        'tax_inclusive', 'write_off_limit', 'max_discount_percentage',
        'ask_for_customer', 'require_shift', 'is_default', 'disabled', 'erpnext_id',
    ];

    protected function casts(): array
    {
        return [
            'allow_rate_change' => 'boolean',
            'allow_discount_change' => 'boolean',
            'allow_negative_stock' => 'boolean',
            'tax_inclusive' => 'boolean',
            'write_off_limit' => 'float',
            'max_discount_percentage' => 'float',
            'ask_for_customer' => 'boolean',
            'require_shift' => 'boolean',
            'is_default' => 'boolean',
            'disabled' => 'boolean',
        ];
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function payments()
    {
        return $this->hasMany(PosProfilePayment::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'pos_profile_users', 'pos_profile_id', 'user_id')
            ->withPivot('is_default')
            ->withTimestamps();
    }

    public function paymentModes(): array
    {
        return $this->payments()->orderByDesc('is_default')->orderBy('mode_of_payment')->pluck('mode_of_payment')->all();
    }
}
