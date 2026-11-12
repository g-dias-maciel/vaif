<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Staff-only audit of one observe-mode conversation against the VAIF
 * methodology. One row per transcript hash; a new message means a new audit.
 */
class ConversationAudit extends Model
{
    use HasUuids;

    public const STATUS_QUEUED = 'queued';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const STATUS_PASS = 'pass';

    public const STATUS_FAIL = 'fail';

    public const STATUS_NA = 'na';

    /** Canonical rubric, in display order. */
    public const STEP_LABELS = [
        'introduction' => 'Apresentou o estúdio/artista',
        'qualification_before_price' => 'Qualificou antes de orçar',
        'price_explained' => 'Explicou o preço com contexto',
        'coverup_handoff' => 'Cobertura/reforma com o artista',
        'offered_slots' => 'Ofereceu horários concretos',
        'deposit_requested' => 'Pediu o sinal antes de confirmar',
        'no_unauthorized_promise' => 'Sem promessa fora da política',
        'handoff_triggers' => 'Encaminhou os casos de handoff',
        'confirmation_after_deposit' => 'Confirmou só depois do sinal',
        'follow_up' => 'Retomou o lead em silêncio',
    ];

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'conversation_audits';

    protected $fillable = [
        'id',
        'artist_id',
        'lead_id',
        'chat_id',
        'transcript_hash',
        'status',
        'score',
        'summary',
        'steps',
        'message_count',
        'last_message_at',
        'model',
        'generated_at',
    ];

    protected $casts = [
        'steps' => 'array',
        'score' => 'float',
        'message_count' => 'integer',
        'last_message_at' => 'datetime',
        'generated_at' => 'datetime',
    ];

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class, 'artist_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    /**
     * Steps in rubric order, tolerating missing keys.
     *
     * @return array<int, array<string, mixed>>
     */
    public function orderedSteps(): array
    {
        $byKey = [];

        foreach ((array) $this->steps as $step) {
            if (is_array($step) && isset($step['key'])) {
                $byKey[(string) $step['key']] = $step;
            }
        }

        $out = [];

        foreach (self::STEP_LABELS as $key => $label) {
            $step = $byKey[$key] ?? ['key' => $key, 'status' => self::STATUS_NA, 'evidence' => '', 'note' => ''];
            $step['label'] = $label;
            $out[] = $step;
        }

        return $out;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function failedSteps(): array
    {
        return array_values(array_filter(
            $this->orderedSteps(),
            fn (array $step) => ($step['status'] ?? null) === self::STATUS_FAIL,
        ));
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_READY => 'Pronta',
            self::STATUS_FAILED => 'Falhou',
            default => 'Na fila',
        };
    }
}
