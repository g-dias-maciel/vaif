<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A price offered for one Project. Standard and promotion quotes share this
 * table: a promotion quote keeps the `second_cap_cents` of the "Leve 2" offer.
 *
 * A quote is a snapshot: later price-book changes never rewrite it. A quote
 * outlives a paused promotion (the Lead keeps the quoted price until
 * `valid_until`); ending a promotion voids its open quotes.
 */
class Quote extends Model
{
    use HasUuids;

    protected $table = 'quotes';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'artist_id',
        'project_id',
        'kind',
        'promotion_id',
        'base_price_cents',
        'offered_price_cents',
        'discount_percent',
        'second_cap_cents',
        'valid_until',
        'status',
        'created_by',
        'created_at',
        'accepted_at',
    ];

    protected $casts = [
        'base_price_cents' => 'integer',
        'offered_price_cents' => 'integer',
        'second_cap_cents' => 'integer',
        'discount_percent' => 'decimal:2',
        'valid_until' => 'datetime',
        'created_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class, 'promotion_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    /**
     * Whether the quote can still be honoured right now. A quote whose
     * `valid_until` passed stays `open` in the database (nothing sweeps it),
     * so validity has to be derived from the date.
     */
    public function isOpen(): bool
    {
        return $this->status === 'open'
            && ($this->valid_until === null || $this->valid_until->isFuture());
    }

    /**
     * Portuguese label for the quote's effective state.
     */
    public function statusLabel(): string
    {
        return match (true) {
            $this->status === 'void' => 'Anulada',
            $this->status === 'accepted' => 'Aceita',
            $this->status === 'declined' => 'Recusada',
            $this->status === 'lapsed' => 'Expirada',
            $this->status === 'open' && ! $this->isOpen() => 'Expirada',
            default => 'Aberta',
        };
    }

    /**
     * Days left in the price-protection window (null when not open).
     */
    public function daysRemaining(): ?int
    {
        if (! $this->isOpen() || $this->valid_until === null) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->valid_until->copy()->startOfDay());
    }

    public function firstPriceLabel(): string
    {
        return $this->money($this->offered_price_cents);
    }

    public function secondCapLabel(): string
    {
        return $this->money($this->second_cap_cents);
    }

    private function money(?int $cents): string
    {
        return 'R$ '.number_format(($cents ?? 0) / 100, 2, ',', '.');
    }
}
