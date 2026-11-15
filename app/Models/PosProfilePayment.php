<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PosProfilePayment extends Model
{
    protected $fillable = ['pos_profile_id', 'mode_of_payment', 'is_default', 'allow_edit_amount'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'allow_edit_amount' => 'boolean'];
    }

    public function posProfile()
    {
        return $this->belongsTo(PosProfile::class);
    }
}
