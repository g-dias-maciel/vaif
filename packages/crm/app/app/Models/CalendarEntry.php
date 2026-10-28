<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An entry on the Artist's calendar: either a `booking` (a Project occupying a
 * slot) or a `block` (a period the agent must not book). Cancelling a booking
 * flips `active` instead of deleting the row, so the history survives.
 */
class CalendarEntry extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'calendar_entries';

    protected $fillable = [
        'id',
        'artist_id',
        'kind',
        'project_id',
        'lead_id',
        'start_at',
        'end_at',
        'active',
        'reason',
        'created_by',
        'google_event_id',
        'google_sync_pending',
        'google_synced_at',
        'google_sync_error',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'active' => 'boolean',
        'google_sync_pending' => 'boolean',
        'google_synced_at' => 'datetime',
    ];

    /**
     * The PDO driver hands timestamptz back with its UTC offset. Show it in the
     * app timezone (America/Sao_Paulo) instead of the raw UTC wall time.
     */
    public function getStartAtAttribute($value): ?Carbon
    {
        return $value ? Carbon::parse($value)->setTimezone(config('app.timezone')) : null;
    }

    public function getEndAtAttribute($value): ?Carbon
    {
        return $value ? Carbon::parse($value)->setTimezone(config('app.timezone')) : null;
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class, 'artist_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    public function isBooking(): bool
    {
        return $this->kind === 'booking';
    }

    public function isBlock(): bool
    {
        return $this->kind === 'block';
    }

    public function durationMinutes(): int
    {
        return max(30, (int) $this->start_at->diffInMinutes($this->end_at));
    }
}
