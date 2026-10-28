<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only audit row. `events` is partitioned by month and uses a bigint
 * sequence primary key, so this model never updates or deletes.
 */
class Event extends Model
{
    protected $table = 'events';

    public $incrementing = true;

    protected $keyType = 'int';

    // The table only records `created_at`.
    public $timestamps = false;

    protected $fillable = [
        'artist_id',
        'lead_id',
        'project_id',
        'actor',
        'type',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
