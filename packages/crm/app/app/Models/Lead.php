<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Lead extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'leads';

    protected $fillable = [
        'id',
        'artist_id',
        'nome',
        'telefone',
        'lead_source',
        'conversation_started',
        'last_message_at',
        'created_at',
    ];

    protected $casts = [
        'conversation_started' => 'datetime',
        'last_message_at' => 'datetime',
    ];

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class, 'artist_id');
    }

    public function tattoos(): HasMany
    {
        return $this->hasMany(Tattoo::class, 'lead_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class, 'lead_id');
    }

    public function promotionQuotes(): HasMany
    {
        return $this->hasMany(PromotionQuote::class, 'lead_id');
    }

    /**
     * The most recent promotion quote matching a tattoo's placement/zone.
     */
    public function quoteFor(?string $placement, ?string $bodyZone): ?PromotionQuote
    {
        if ($placement === null || $bodyZone === null) {
            return null;
        }

        if (! $this->relationLoaded('promotionQuotes')) {
            $this->load('promotionQuotes');
        }

        return $this->promotionQuotes
            ->filter(fn (PromotionQuote $quote) => $quote->placement === $placement
                && $quote->body_zone === $bodyZone)
            ->sortByDesc(fn (PromotionQuote $quote) => $quote->quoted_at)
            ->first();
    }

    /**
     * Deep link to the Lead's WhatsApp chat, or null when no phone is set.
     */
    public function getWhatsappUrlAttribute(): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $this->telefone);

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
            ->where('telefone', $this->telefone)
            ->exists();
    }
}
