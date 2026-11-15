<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'customer_code', 'customer_name', 'customer_group_id', 'territory_id',
        'email', 'phone', 'address_line1', 'city', 'country',
        'loyalty_points', 'credit_limit', 'is_walk_in', 'notes', 'erpnext_id',
    ];

    protected function casts(): array
    {
        return [
            'loyalty_points' => 'integer',
            'credit_limit' => 'float',
            'is_walk_in' => 'boolean',
        ];
    }

    public function group()
    {
        return $this->belongsTo(CustomerGroup::class, 'customer_group_id');
    }

    public function territory()
    {
        return $this->belongsTo(Territory::class);
    }

    public function invoices()
    {
        return $this->hasMany(Invoice::class);
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (! $term) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('customer_name', 'like', "%{$term}%")
                ->orWhere('customer_code', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%");
        });
    }
}
