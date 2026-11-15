<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Uom extends Model
{
    protected $fillable = ['uom_name', 'must_be_whole_number'];

    protected function casts(): array
    {
        return ['must_be_whole_number' => 'boolean'];
    }
}
