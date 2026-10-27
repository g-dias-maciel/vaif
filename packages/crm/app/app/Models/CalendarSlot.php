<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarSlot extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    // The calendar table predates Laravel and only has `created_at`.
    public $timestamps = false;

    protected $table = 'calendar';

    protected $fillable = [
        'id',
        'artist_id',
        'start_at',
        'end_at',
        'type',
        'lead_id',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
    ];

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class, 'artist_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }
}
