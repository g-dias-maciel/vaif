<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A proposed write from observe mode that touches money or the calendar.
 *
 * High-confidence two-sided signals can be applied immediately and are stored
 * as `auto_applied` for audit. Everything else waits in `pending`.
 */
class ObservedWrite extends Model
{
    use HasUuids;

    public const KIND_BOOKING = 'booking';

    public const KIND_RESCHEDULE = 'reschedule';

    public const KIND_DEPOSIT_REQUEST = 'deposit_request';

    public const KIND_DEPOSIT_CONFIRM = 'deposit_confirm';

    public const KIND_QUOTE = 'quote';

    public const KIND_STAGE = 'stage';

    public const KIND_NOTE = 'note';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUS_AUTO_APPLIED = 'auto_applied';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'observed_writes';

    protected $fillable = [
        'id',
        'artist_id',
        'lead_id',
        'project_id',
        'chat_id',
        'observed_turn_id',
        'kind',
        'status',
        'dedupe_key',
        'confidence',
        'payload',
        'evidence',
        'decided_by',
        'decided_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'evidence' => 'array',
        'confidence' => 'float',
        'decided_at' => 'datetime',
    ];

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class, 'artist_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function kindLabel(): string
    {
        return match ($this->kind) {
            self::KIND_BOOKING => 'Agendamento',
            self::KIND_RESCHEDULE => 'Remarcação',
            self::KIND_DEPOSIT_REQUEST => 'Pedido de sinal',
            self::KIND_DEPOSIT_CONFIRM => 'Confirmação de sinal',
            self::KIND_QUOTE => 'Orçamento',
            self::KIND_STAGE => 'Etapa',
            default => 'Observação',
        };
    }

    /**
     * One-line description for the queue, built only from the payload.
     */
    public function summary(): string
    {
        return match ($this->kind) {
            self::KIND_BOOKING, self::KIND_RESCHEDULE => $this->dateSummary(),
            self::KIND_QUOTE => isset($this->payload['price_cents'])
                ? 'R$ '.number_format(((int) $this->payload['price_cents']) / 100, 2, ',', '.')
                : '—',
            self::KIND_DEPOSIT_REQUEST => isset($this->payload['amount_cents'])
                ? 'Sinal de R$ '.number_format(((int) $this->payload['amount_cents']) / 100, 2, ',', '.')
                : 'Pedido de sinal',
            self::KIND_DEPOSIT_CONFIRM => 'Sinal recebido',
            self::KIND_STAGE => (string) ($this->payload['stage_key'] ?? '—'),
            default => (string) ($this->payload['text'] ?? '—'),
        };
    }

    private function dateSummary(): string
    {
        $iso = (string) ($this->payload['start_at'] ?? '');

        if ($iso === '') {
            return '—';
        }

        try {
            return \Illuminate\Support\Carbon::parse($iso)
                ->setTimezone(config('app.timezone'))
                ->format('d/m/Y H:i');
        } catch (\Throwable) {
            return $iso;
        }
    }
}
