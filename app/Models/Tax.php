<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tax extends Model
{
    protected $fillable = ['name', 'description', 'rate', 'included_in_price', 'account_name', 'is_default', 'erpnext_id'];

    protected function casts(): array
    {
        return [
            'rate' => 'float',
            'included_in_price' => 'boolean',
            'is_default' => 'boolean',
        ];
    }
}
