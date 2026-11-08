<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An Artist prospect in VAIF's own sales pipeline.
 *
 * A prospect is an Artist VAIF is signing as a client — it is never a
 * tattoo-customer Lead. The Artist-facing CRM never reads this model.
 */
class Prospect extends Model
{
    use HasUuids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_BACKLOG = 'backlog';

    public const STATUS_ARCHIVED = 'archived';

    public const BAND_FULL_SERVICE = 'full_service';

    public const BAND_RETAINER = 'retainer';

    public const BAND_ENTRY = 'entry';

    public const BAND_NURTURE = 'nurture';

    public const BAND_UNKNOWN = 'unknown';

    /** R$75.000,00 */
    public const FULL_SERVICE_THRESHOLD_CENTS = 7_500_000;

    /** R$15.000,00 */
    public const RETAINER_THRESHOLD_CENTS = 1_500_000;

    /** R$4.000,00 */
    public const ENTRY_THRESHOLD_CENTS = 400_000;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'prospects';

    protected $fillable = [
        'id',
        'name',
        'whatsapp_e164',
        'whatsapp_raw',
        'instagram',
        'email',
        'studio',
        'style',
        'city',
        'source',
        'status',
        'stage_key',
        'revenue_monthly_cents',
        'ticket_cents',
        'sessions_monthly',
        'admin_hours_day',
        'hidden_loss_cents',
        'potential_revenue_cents',
        'revenue_band',
        'score',
        'owner_id',
        'campaign_id',
        'booked_call_at',
        'last_contact_at',
        'next_action_at',
        'next_action_note',
        'lost_reason',
        'notes',
        'artist_id',
        'handoff_at',
        'legacy_id',
        'legacy_notion_id',
        'legacy_status',
        'import_flags',
        'raw_payload',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $prospect): void {
            $prospect->revenue_band = self::bandForCents($prospect->revenue_monthly_cents);
            $prospect->score = self::scoreFor(
                $prospect->revenue_monthly_cents,
                $prospect->ticket_cents,
                $prospect->sessions_monthly,
                $prospect->admin_hours_day !== null ? (float) $prospect->admin_hours_day : null,
            );
        });
    }

    protected $casts = [
        'revenue_monthly_cents' => 'integer',
        'ticket_cents' => 'integer',
        'sessions_monthly' => 'integer',
        'admin_hours_day' => 'decimal:2',
        'hidden_loss_cents' => 'integer',
        'potential_revenue_cents' => 'integer',
        'score' => 'integer',
        'booked_call_at' => 'datetime',
        'last_contact_at' => 'datetime',
        'next_action_at' => 'datetime',
        'handoff_at' => 'datetime',
        'import_flags' => 'array',
        'raw_payload' => 'array',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class, 'artist_id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(ProspectStage::class, 'stage_key', 'key');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(ProspectActivity::class, 'prospect_id');
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(ProspectContract::class, 'prospect_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeBacklog(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_BACKLOG);
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ARCHIVED);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isBacklog(): bool
    {
        return $this->status === self::STATUS_BACKLOG;
    }

    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }

    /**
     * Revenue band used to route the offer:
     * full service >= R$75k, retainer >= R$15k, 697 >= R$4k, nurture below.
     */
    public static function bandForCents(?int $revenueCents): string
    {
        if ($revenueCents === null || $revenueCents <= 0) {
            return self::BAND_UNKNOWN;
        }

        return match (true) {
            $revenueCents >= self::FULL_SERVICE_THRESHOLD_CENTS => self::BAND_FULL_SERVICE,
            $revenueCents >= self::RETAINER_THRESHOLD_CENTS => self::BAND_RETAINER,
            $revenueCents >= self::ENTRY_THRESHOLD_CENTS => self::BAND_ENTRY,
            default => self::BAND_NURTURE,
        };
    }

    /**
     * Prioritization score, 0–100. It is a queue order, not a gate: a low score
     * never blocks a human from qualifying the prospect.
     */
    public static function scoreFor(
        ?int $revenueCents,
        ?int $ticketCents,
        ?int $sessionsMonthly,
        ?float $adminHoursDay,
    ): int {
        $score = 0;

        $revenue = (int) ($revenueCents ?? 0);
        $score += match (true) {
            $revenue >= self::FULL_SERVICE_THRESHOLD_CENTS => 40,
            $revenue >= 2_000_000 => 35,   // R$20k
            $revenue >= self::RETAINER_THRESHOLD_CENTS => 30,
            $revenue >= 1_000_000 => 20,   // R$10k
            $revenue >= self::ENTRY_THRESHOLD_CENTS => 10,
            default => 0,
        };

        $ticket = (int) ($ticketCents ?? 0);
        $score += match (true) {
            $ticket >= 300_000 => 25,      // R$3k
            $ticket >= 150_000 => 20,      // R$1.5k
            $ticket >= 100_000 => 15,      // R$1k
            $ticket >= 50_000 => 10,       // R$500
            default => 5,
        };

        $sessions = (int) ($sessionsMonthly ?? 0);
        $score += match (true) {
            $sessions >= 20 => 20,
            $sessions >= 15 => 16,
            $sessions >= 10 => 12,
            $sessions >= 5 => 8,
            default => 4,
        };

        $hours = (float) ($adminHoursDay ?? 0);
        $score += match (true) {
            $hours >= 4 => 15,
            $hours >= 2 => 10,
            $hours >= 1 => 5,
            default => 0,
        };

        return max(0, min(100, $score));
    }

    /**
     * @return array<string, string>
     */
    public static function bandLabels(): array
    {
        return [
            self::BAND_FULL_SERVICE => 'Full service',
            self::BAND_RETAINER => 'Retainer',
            self::BAND_ENTRY => '697',
            self::BAND_NURTURE => 'Nutrição',
            self::BAND_UNKNOWN => 'Sem dado',
        ];
    }

    public function bandLabel(): string
    {
        return self::bandLabels()[$this->revenue_band] ?? 'Sem dado';
    }

    public function revenueLabel(): string
    {
        if ($this->revenue_monthly_cents === null) {
            return '—';
        }

        return 'R$ '.number_format($this->revenue_monthly_cents / 100, 0, ',', '.');
    }

    /**
     * Normalize a Brazilian phone number to E.164 (`+55...`). Returns null for
     * input that cannot be a 10/11-digit number or an already-prefixed one.
     */
    public static function normalizePhone(mixed $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $raw) ?? '';

        if ($digits === '') {
            return null;
        }

        if (str_starts_with($digits, '55') && strlen($digits) > 11) {
            return strlen($digits) <= 13 ? '+'.$digits : null;
        }

        if (strlen($digits) === 10 || strlen($digits) === 11) {
            return '+55'.$digits;
        }

        return null;
    }

    public function whatsappUrl(): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $this->whatsapp_e164) ?? '';

        return $digits !== '' ? 'https://wa.me/'.$digits : null;
    }

    public function instagramUrl(): ?string
    {
        return $this->instagram ? 'https://instagram.com/'.$this->instagram : null;
    }

    public function displayPhone(): string
    {
        $digits = preg_replace('/\D+/', '', (string) $this->whatsapp_e164) ?? '';

        if (str_starts_with($digits, '55') && strlen($digits) >= 12) {
            $digits = substr($digits, 2);
        }

        if (strlen($digits) === 11) {
            return sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 5), substr($digits, 7));
        }

        if (strlen($digits) === 10) {
            return sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 4), substr($digits, 6));
        }

        return (string) $this->whatsapp_e164;
    }
}
