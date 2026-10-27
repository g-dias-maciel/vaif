<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A promotion quote sent to a Lead: the price-protected offer for one
 * placement, valid for `quote_valid_days` from the moment it was sent.
 *
 * A quote outlives a paused promotion (the lead keeps the quoted price until
 * `valid_until`); ending a promotion voids its open quotes.
 */
class PromotionQuote extends Model
{
    use HasUuids;

    protected $table = 'promotion_quotes';

    // The table records `quoted_at`/`created_at`; it has no `updated_at`.
    public $timestamps = false;

    protected $fillable = [
        'id',
        'promotion_id',
        'lead_id',
        'artist_id',
        'placement',
        'body_zone',
        'first_price',
        'second_cap_price',
        'quoted_at',
        'valid_until',
        'status',
        'secured_at',
        'created_at',
    ];

    protected $casts = [
        'first_price' => 'integer',
        'second_cap_price' => 'integer',
        'quoted_at' => 'datetime',
        'valid_until' => 'datetime',
        'secured_at' => 'datetime',
    ];

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class, 'promotion_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    /**
     * Whether the quote can still be honoured right now. A quote whose
     * `valid_until` passed stays `open` in the database (nothing sweeps it),
     * so validity has to be derived from the date.
     */
    public function isOpen(): bool
    {
        return $this->status === 'open'
            && $this->valid_until !== null
            && $this->valid_until->isFuture();
    }

    /**
     * Portuguese label for the quote's effective state.
     */
    public function statusLabel(): string
    {
        return match (true) {
            $this->status === 'void' => 'Anulada',
            $this->status === 'secured' => 'Garantida',
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
        if (! $this->isOpen()) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->valid_until->copy()->startOfDay());
    }

    public function firstPriceLabel(): string
    {
        return $this->money($this->first_price);
    }

    public function secondCapLabel(): string
    {
        return $this->money($this->second_cap_price);
    }

    private function money(?int $cents): string
    {
        return 'R$ '.number_format(($cents ?? 0) / 100, 2, ',', '.');
    }
}
