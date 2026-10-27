<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * A person who contacted the Artist — and, in the shared Postgres schema, also
 * the single tattoo project attached to that conversation.
 *
 * The SDR agent (Beatriz) writes the project fields (placement, prices,
 * pipeline_status, booked_date, …) onto `leads`; there is no separate
 * `tattoos` table. One Lead = one pipeline card.
 */
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
        'conversation_started',
        'last_message_at',
        'created_at',
    ];

    protected $casts = [
        'conversation_started' => 'datetime',
        'last_message_at' => 'datetime',
        'booked_date' => 'datetime',
        'primeira_tatuagem' => 'boolean',
    ];

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class, 'artist_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class, 'lead_id');
    }

    public function promotionQuotes(): HasMany
    {
        return $this->hasMany(PromotionQuote::class, 'lead_id');
    }

    public function calendar(): HasMany
    {
        return $this->hasMany(CalendarSlot::class, 'lead_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'lead_id');
    }

    /**
     * The most recent promotion quote matching this project's placement/zone.
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

    /**
     * The amount charged, in centavos: negotiated when present, else table price.
     */
    public function getChargedPriceCentsAttribute(): ?int
    {
        $price = $this->negotiated_price ?? $this->table_price;

        return $price === null ? null : (int) $price;
    }

    /**
     * Reference picture URLs.
     *
     * The shared Postgres column is `TEXT[]`, so PDO hands us a Postgres array
     * literal (`{"https://…","https://…"}`); SQLite (local dev/tests) stores the
     * same value as JSON. Parse both rather than assuming a single encoding —
     * the model's `array` cast would silently return null for the former.
     *
     * @return array<int, string>|null
     */
    public function getReferencePicsAttribute($value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return array_values($value);
        }

        $value = trim((string) $value);

        if (str_starts_with($value, '{')) {
            return self::parsePostgresArray($value);
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? array_values($decoded) : null;
    }

    /**
     * @return array<int, string>
     */
    private static function parsePostgresArray(string $literal): array
    {
        $inner = trim($literal, '{}');

        if ($inner === '') {
            return [];
        }

        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|([^,]+)/', $inner, $matches, PREG_SET_ORDER);

        $values = [];

        foreach ($matches as $match) {
            if (str_starts_with($match[0], '"')) {
                $values[] = str_replace(['\\\\', '\\"'], ['\\', '"'], $match[1]);
                continue;
            }

            $value = trim($match[2] ?? '');

            if ($value !== '' && strcasecmp($value, 'NULL') !== 0) {
                $values[] = $value;
            }
        }

        return $values;
    }
}
