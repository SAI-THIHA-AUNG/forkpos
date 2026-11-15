<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosProfileUser extends Model
{
    protected $fillable = ['pos_profile_id', 'user_id', 'is_default'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }
}
