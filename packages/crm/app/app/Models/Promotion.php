<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A pre-approved offer the SDR agent presents alongside the price. Mechanics
 * are defined centrally (promotion_types); a VAIF admin owns the Artist-specific
 * price/conditions and activation. One Promotion is active per Artist.
 */
class Promotion extends Model
{
    use HasUuids;

    protected $table = 'promotions';

    protected $fillable = [
        'id',
        'artist_id',
        'type_key',
        'name',
        'multiplier',
        'audience',
        'conditions',
        'quote_valid_days',
        'status',
        'activated_at',
        'paused_at',
        'ended_at',
    ];

    protected $casts = [
        'conditions' => 'array',
        'multiplier' => 'float',
        'quote_valid_days' => 'integer',
        'activated_at' => 'datetime',
        'paused_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class, 'artist_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(PromotionType::class, 'type_key', 'key');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class, 'promotion_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function statusLabel(): string
    {
        return [
            'draft' => 'Rascunho',
            'active' => 'Ativa',
            'paused' => 'Pausada',
            'ended' => 'Encerrada',
        ][$this->status] ?? ucfirst((string) $this->status);
    }

    public function audienceLabel(): string
    {
        return [
            'all' => 'Todos os clientes',
            'new' => 'Clientes novos',
            'returning' => 'Recorrentes',
        ][$this->audience] ?? ucfirst((string) $this->audience);
    }

    /**
     * "2x", "2,5x" — the multiplier that describes the offer mechanic.
     */
    public function multiplierLabel(): string
    {
        $value = rtrim(rtrim(number_format((float) $this->multiplier, 2, ',', ''), '0'), ',');

        return $value.'x';
    }
}
