<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Staff review of one copilot draft. `needs_fix` marks a case Beatriz should
 * have answered differently; `corrected_reply` is the target for calibration.
 */
class AgentDraftReview extends Model
{
    use HasUuids;

    public const VERDICT_GOOD = 'good';
    public const VERDICT_NEEDS_FIX = 'needs_fix';

    public const CATEGORIES = [
        'qualification' => 'Qualificação',
        'handoff' => 'Handoff',
        'price' => 'Preço/orçamento',
        'policy' => 'Política do estúdio',
        'context' => 'Contexto/histórico',
        'tone' => 'Tom/tamanho',
        'media' => 'Mídia',
        'other' => 'Outro',
    ];

    protected $table = 'agent_draft_reviews';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'artist_id',
        'agent_draft_id',
        'lead_id',
        'reviewer_id',
        'verdict',
        'category',
        'note',
        'corrected_reply',
    ];

    public function draft(): BelongsTo
    {
        return $this->belongsTo(AgentDraft::class, 'agent_draft_id');
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function needsFix(): bool
    {
        return $this->verdict === self::VERDICT_NEEDS_FIX;
    }
}
