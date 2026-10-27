<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tattoo extends Model
{
    use HasUuids;

    protected $table = 'tattoos';

    protected $fillable = [
        'id',
        'lead_id',
        'artist_id',
        'placement',
        'body_zone',
        'style',
        'primeira_tatuagem',
        'significado',
        'tipo_tatuagem',
        'reference_pics',
        'table_price',
        'negotiated_price',
        'discount_percent',
        'contrapartida',
        'deposit_amount',
        'deposit_status',
        'pipeline_status',
        'handoff_reason',
        'booked_date',
        'session_duration_min',
        'buffer_min',
        'created_at',
    ];

    protected $casts = [
        'primeira_tatuagem' => 'boolean',
        'reference_pics' => 'array',
        'booked_date' => 'datetime',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class, 'artist_id');
    }

    public function calendar(): HasMany
    {
        return $this->hasMany(CalendarSlot::class, 'tattoo_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'tattoo_id');
    }

    /**
     * The amount charged for this tattoo, in centavos: negotiated price when
     * present, otherwise the table price.
     */
    public function getChargedPriceCentsAttribute(): ?int
    {
        return $this->negotiated_price ?? $this->table_price;
    }

    public function getBoardStatusAttribute(): string
    {
        return $this->pipeline_status;
    }
}
