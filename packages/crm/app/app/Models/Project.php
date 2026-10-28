<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One tattoo job. A Lead (person) can own several Projects; each Project is a
 * single card on the pipeline board and carries its own quotes, payments and
 * calendar entries.
 */
class Project extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'projects';

    protected $fillable = [
        'id',
        'artist_id',
        'lead_id',
        'stage_key',
        'placement',
        'body_zone',
        'style',
        'first_tattoo',
        'meaning',
        'tattoo_type',
        'reference_pics',
        'handoff_reason',
        'accepted_quote_id',
        'closed_at',
    ];

    protected $casts = [
        'first_tattoo' => 'boolean',
        'closed_at' => 'datetime',
    ];

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class, 'artist_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    public function quotes(): HasMany
    {
        return $this->hasMany(Quote::class, 'project_id');
    }

    public function latestQuote(): HasOne
    {
        return $this->hasOne(Quote::class, 'project_id')->orderByDesc('created_at');
    }

    public function acceptedQuote(): BelongsTo
    {
        return $this->belongsTo(Quote::class, 'accepted_quote_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'project_id');
    }

    public function calendar(): HasMany
    {
        return $this->hasMany(CalendarEntry::class, 'project_id');
    }

    public function openQuote(): HasOne
    {
        return $this->hasOne(Quote::class, 'project_id')->where('status', 'open');
    }

    /**
     * The amount charged, in centavos: the accepted quote when there is one,
     * else the latest offer (open or lapsed) sent to the Lead.
     */
    public function getChargedPriceCentsAttribute(): ?int
    {
        $quote = $this->acceptedQuote ?? $this->latestQuote;

        return $quote?->offered_price_cents;
    }

    /**
     * The deposit attached to this Project (requested or confirmed), if any.
     */
    public function getDepositAttribute(): ?Payment
    {
        if ($this->relationLoaded('payments')) {
            return $this->payments
                ->where('kind', 'deposit')
                ->whereIn('status', ['requested', 'confirmed'])
                ->sortByDesc('requested_at')
                ->first();
        }

        return $this->payments()
            ->where('kind', 'deposit')
            ->whereIn('status', ['requested', 'confirmed'])
            ->orderByDesc('requested_at')
            ->first();
    }

    /**
     * `confirmado` | `aguardando_confirmacao` | `nao_solicitado` — the same
     * vocabulary the v1 UI used, derived from `payments`.
     */
    public function getDepositStatusAttribute(): string
    {
        $deposit = $this->deposit;

        return match ($deposit?->status) {
            'confirmed' => 'confirmado',
            'requested' => 'aguardando_confirmacao',
            default => 'nao_solicitado',
        };
    }

    /**
     * The active booking for this Project.
     */
    public function getBookingAttribute(): ?CalendarEntry
    {
        $entries = $this->relationLoaded('calendar')
            ? $this->calendar
            : $this->calendar()->get();

        return $entries
            ->where('kind', 'booking')
            ->where('active', true)
            ->sortByDesc('start_at')
            ->first();
    }

    public function getBookedAtAttribute(): ?\Illuminate\Support\Carbon
    {
        return $this->booking?->start_at;
    }

    public function getWhatsappUrlAttribute(): ?string
    {
        return $this->lead?->whatsapp_url;
    }

    public function getIsReturningAttribute(): bool
    {
        return (bool) $this->lead?->is_returning;
    }

    public function getStageLabelAttribute(): string
    {
        return config('crm.pipeline_statuses')[$this->stage_key] ?? ucfirst((string) $this->stage_key);
    }

    /**
     * Portuguese label for the tattoo type (v2 stores `new` / `cover_up`).
     */
    public function getTattooTypeLabelAttribute(): string
    {
        return match ($this->tattoo_type) {
            'cover_up' => 'Sim (cobertura)',
            'rework'   => 'Sim (reforma)',
            'new'      => 'Não (tatuagem nova)',
            default    => '—',
        };
    }

    /**
     * Reference picture URLs.
     *
     * The Postgres column is `TEXT[]`, so PDO hands us a Postgres array literal
     * (`{"https://…","https://…"}`); SQLite (local dev/tests) stores the same
     * value as JSON. Parse both rather than assuming a single encoding.
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

    public function setReferencePicsAttribute($value): void
    {
        $this->attributes['reference_pics'] = is_array($value) ? '{'.implode(',', array_map(
            fn (string $pic) => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $pic).'"',
            $value,
        )).'}' : $value;
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
