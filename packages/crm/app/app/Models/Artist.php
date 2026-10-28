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

    protected $table = 'artists';

    protected $fillable = [
        'id',
        'name',
        'status',
        'timezone',
        'wa_session_slug',
        'onboarding_token',
        'onboarded_at',
        'google_calendar_id',
        'google_calendar_summary',
        'google_calendar_timezone',
        'google_connected_at',
    ];

    protected $casts = [
        'onboarded_at' => 'datetime',
        'google_connected_at' => 'datetime',
    ];

    public function settings(): HasOne
    {
        return $this->hasOne(ArtistSetting::class, 'artist_id');
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'artist_id');
    }

    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class, 'artist_id');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'artist_id');
    }

    public function calendar(): HasMany
    {
        return $this->hasMany(CalendarEntry::class, 'artist_id');
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

    public function hasGoogleCalendar(): bool
    {
        return filled($this->google_calendar_id);
    }

    /**
     * The timezone used when writing Google Calendar events. The Artist's
     * booking timezone is authoritative; the calendar's own timezone only
     * controls how Google displays the event.
     */
    public function calendarTimezone(): string
    {
        return $this->timezone
            ?: $this->google_calendar_timezone
            ?: 'America/Sao_Paulo';
    }

    /**
     * What the Artist sees as their name: the display name from settings,
     * falling back to the legal/company name.
     */
    public function displayName(): string
    {
        return $this->settings?->display_name ?: $this->name;
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
