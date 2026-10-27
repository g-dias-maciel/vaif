<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Event extends Model
{
    use HasUuids;

    // The events table predates Laravel and only has `created_at`.
    public $timestamps = false;

    protected $table = 'events';

    protected $fillable = [
        'lead_id',
        'tattoo_id',
        'artist_id',
        'event_type',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }
}
