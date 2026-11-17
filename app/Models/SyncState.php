<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Progress + delta cursor of one entity for one POS profile. */
class SyncState extends Model
{
    public const PENDING = 'pending';

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    protected $fillable = ['pos_profile_id', 'entity', 'status', 'cursor', 'rows', 'error', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime', 'rows' => 'integer'];
    }

    public function posProfile(): BelongsTo
    {
        return $this->belongsTo(PosProfile::class);
    }
}
