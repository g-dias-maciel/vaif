<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One observed unit of conversation. Written by observe mode; never produces a
 * reply. Used for audit, dedupe and later phases (quotes, bookings, audits).
 */
class ObservedTurn extends Model
{
    use HasUuids;

    public const DIRECTION_INBOUND = 'inbound';

    public const DIRECTION_OUTBOUND = 'outbound';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'observed_turns';

    protected $fillable = [
        'id',
        'artist_id',
        'lead_id',
        'project_id',
        'chat_id',
        'direction',
        'wa_message_id',
        'occurred_at',
        'text',
        'text_hash',
        'intent',
        'entities',
        'facts',
        'model',
        'status',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'entities' => 'array',
        'facts' => 'array',
    ];

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class, 'artist_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }
}
