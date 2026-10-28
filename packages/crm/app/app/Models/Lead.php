<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * A person who contacted the Artist. A Lead is not a job: the same person can
 * have several Projects (a returning customer, two tattoos in one deal).
 */
class Lead extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'leads';

    protected $fillable = [
        'id',
        'artist_id',
        'name',
        'phone_e164',
        'phone_raw',
        'source',
        'first_seen_at',
        'last_message_at',
        'archived_at',
        'anonymized_at',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_message_at' => 'datetime',
        'archived_at' => 'datetime',
        'anonymized_at' => 'datetime',
    ];

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class, 'artist_id');
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class, 'lead_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class, 'lead_id');
    }

    public function calendar(): HasMany
    {
        return $this->hasMany(CalendarEntry::class, 'lead_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'lead_id');
    }

    /**
     * What to show as the Lead's name when they never gave one.
     */
    public function displayName(): string
    {
        return $this->name ?: 'Sem nome';
    }

    /**
     * Deep link to the Lead's WhatsApp chat, or null when no phone is set.
     */
    public function getWhatsappUrlAttribute(): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $this->phone_e164);

        return $digits !== '' ? 'https://wa.me/'.$digits : null;
    }

    /**
     * Whether this Lead is an old / returning client: their phone is already
     * in the returning_customers table (a prior confirmed tattoo or an import).
     */
    public function getIsReturningAttribute(): bool
    {
        return DB::table('returning_customers')
            ->where('artist_id', $this->artist_id)
            ->where('phone_e164', $this->phone_e164)
            ->exists();
    }
}
