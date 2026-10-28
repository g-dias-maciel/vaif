<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A row of the versioned price book. Closing a row (`effective_to`) keeps
 * history for every quote already sent; the CRM only edits the current rows.
 */
class PriceBook extends Model
{
    use HasUuids;

    protected $table = 'price_book';

    // The table only records `created_at`.
    public $timestamps = false;

    protected $fillable = [
        'id',
        'artist_id',
        'placement',
        'body_zone',
        'base_price_cents',
        'session_duration_min',
        'buffer_min',
        'effective_from',
        'effective_to',
    ];

    protected $casts = [
        'base_price_cents' => 'integer',
        'session_duration_min' => 'integer',
        'buffer_min' => 'integer',
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
    ];

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class, 'artist_id');
    }

    public function isCurrent(): bool
    {
        return $this->effective_to === null;
    }
}
