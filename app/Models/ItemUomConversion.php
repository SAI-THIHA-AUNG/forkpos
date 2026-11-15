<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ItemUomConversion extends Model
{
    protected $fillable = ['item_id', 'uom_id', 'conversion_factor'];

    protected function casts(): array
    {
        return ['conversion_factor' => 'float'];
    }
}
