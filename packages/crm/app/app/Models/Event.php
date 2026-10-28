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

    public function getLabelAttribute(): string
    {
        return config('crm.event_labels')[$this->type]
            ?? ucfirst(str_replace('_', ' ', (string) $this->type));
    }

    /**
     * One short, human-readable line for the timeline. The payload stays the
     * source of truth; this only surfaces the field the artist cares about.
     */
    public function getDetailAttribute(): ?string
    {
        $p = $this->payload ?: [];
        $money = fn ($cents) => is_numeric($cents)
            ? 'R$ '.number_format(((int) $cents) / 100, 2, ',', '.')
            : null;

        return match ($this->type) {
            'stage_changed' => isset($p['stage'])
                ? '→ '.(config('crm.pipeline_statuses')[$p['stage']] ?? $p['stage'])
                : null,
            'quote_sent' => $money($p['offered_price_cents'] ?? $p['base_price_cents'] ?? null),
            'promotion_quote_sent' => $money($p['offered_price_cents'] ?? null),
            'deposit_requested' => $money($p['amount_cents'] ?? null),
            'deposit_confirmed' => isset($p['method'])
                ? 'via '.strtoupper((string) $p['method'])
                : null,
            'booking_created', 'booking_rescheduled' => isset($p['start_at'])
                ? \Illuminate\Support\Carbon::parse($p['start_at'])->format('d/m/Y H:i')
                : null,
            'project_updated' => isset($p['fields']) && is_array($p['fields'])
                ? implode(', ', $p['fields'])
                : null,
            'handoff_triggered' => $p['reason'] ?? null,
            default => null,
        };
    }
}
