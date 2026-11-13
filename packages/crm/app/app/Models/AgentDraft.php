<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A copilot draft: the reply the agent would have sent, kept for calibration.
 *
 * The draft is paired with the artist's real WhatsApp reply (`fromMe`) and a
 * comparison (deterministic rules + LLM judge) is stored in `comparison`.
 */
class AgentDraft extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';
    public const STATUS_REPLIED = 'replied';
    public const STATUS_COMPARED = 'compared';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_SUPERSEDED = 'superseded';
    public const STATUS_SIMULATED = 'simulated';

    public const SOURCE_LIVE = 'live';
    public const SOURCE_SIMULATED = 'simulated';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'agent_drafts';

    protected $fillable = [
        'id',
        'artist_id',
        'lead_id',
        'project_id',
        'chat_id',
        'turn_id',
        'lead_text',
        'lead_msg_at',
        'lead_message_id',
        'source',
        'intent',
        'intent_confidence',
        'facts',
        'draft_reply',
        'claims',
        'validation',
        'simulated_actions',
        'model',
        'prompt_version',
        'latency_ms',
        'status',
        'artist_reply',
        'artist_media_note',
        'artist_media_ids',
        'artist_replied_at',
        'response_seconds',
        'artist_claims',
        'artist_validation',
        'comparison',
        'compared_at',
    ];

    protected $casts = [
        'facts' => 'array',
        'claims' => 'array',
        'validation' => 'array',
        'simulated_actions' => 'array',
        'artist_claims' => 'array',
        'artist_validation' => 'array',
        'artist_media_ids' => 'array',
        'comparison' => 'array',
        'intent_confidence' => 'float',
        'lead_message_id' => 'integer',
        'response_seconds' => 'integer',
        'lead_msg_at' => 'datetime',
        'artist_replied_at' => 'datetime',
        'compared_at' => 'datetime',
    ];

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class, 'artist_id');
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_REPLIED], true);
    }

    public function isSimulated(): bool
    {
        return $this->source === self::SOURCE_SIMULATED;
    }

    public function judgeVerdict(): ?string
    {
        $judge = $this->comparison['judge'] ?? null;

        return is_array($judge) ? ($judge['verdict'] ?? null) : null;
    }

    public function wasAccepted(): ?bool
    {
        $judge = $this->comparison['judge'] ?? null;

        return is_array($judge) && array_key_exists('acceptable', $judge)
            ? (bool) $judge['acceptable']
            : null;
    }
}
