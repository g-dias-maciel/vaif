<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Artist extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    // The artists table predates Laravel and only has `created_at`.
    public $timestamps = false;

    protected $table = 'artists';

    protected $fillable = [
        'id',
        'nome',
        'display_name',
        'status',
        'timezone',
        'instagram_handle',
        'whatsapp_number',
        'wa_session_slug',
        'pricing_extras',
        'deposit_type',
        'deposit_value',
        'working_hours',
        'pix_key',
        'floor_pct',
        'specialties',
        'nao_faco',
    ];

    protected $casts = [
        'specialties' => 'array',
        'nao_faco' => 'array',
        'working_hours' => 'array',
        'ai_active_hours' => 'array',
        'pricing_extras' => 'array',
    ];

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'artist_id');
    }

    public function calendar(): HasMany
    {
        return $this->hasMany(CalendarSlot::class, 'artist_id');
    }

    public function promotions(): HasMany
    {
        return $this->hasMany(Promotion::class, 'artist_id');
    }

    /**
     * The single pre-approved offer currently live for this Artist.
     */
    public function activePromotion(): HasOne
    {
        return $this->hasOne(Promotion::class, 'artist_id')->where('status', 'active');
    }

    /**
     * Pause or reactivate the SDR agent for this Artist, returning the
     * flash message to show. Only flips live <-> suspended.
     */
    public function toggleAgent(): string
    {
        if ($this->status === 'live') {
            $this->update(['status' => 'suspended']);

            return 'Atendimento pausado. A Beatriz não vai mais responder novos contatos até reativar.';
        }

        if ($this->status === 'suspended') {
            $this->update(['status' => 'live']);

            return 'Atendimento reativado. A Beatriz já volta a responder seus contatos.';
        }

        return 'O atendimento não pode ser alterado no status atual.';
    }
}
