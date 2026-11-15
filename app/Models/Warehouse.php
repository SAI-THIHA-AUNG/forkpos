<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    protected $fillable = ['name', 'warehouse_code', 'is_group', 'is_default', 'erpnext_id'];

    protected function casts(): array
    {
        return ['is_group' => 'boolean', 'is_default' => 'boolean'];
    }

    public function balances()
    {
        return $this->hasMany(StockBalance::class);
    }
}
