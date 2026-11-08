<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One event on a Prospect's timeline: a call, a WhatsApp message, a note, a
 * task, a stage change, an import or the onboarding handoff.
 */
class ProspectActivity extends Model
{
    public const TYPE_CALL = 'call';

    public const TYPE_WHATSAPP = 'whatsapp';

    public const TYPE_NOTE = 'note';

    public const TYPE_TASK = 'task';

    public const TYPE_STAGE_CHANGE = 'stage_change';

    public const TYPE_HANDOFF = 'handoff';

    public const TYPE_IMPORT = 'import';

    public const TYPE_CONTRACT = 'contract';

    public const UPDATED_AT = null;

    protected $table = 'prospect_activities';

    protected $fillable = [
        'prospect_id',
        'user_id',
        'type',
        'title',
        'body',
        'due_at',
        'completed_at',
        'metadata',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'completed_at' => 'datetime',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class, 'prospect_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('completed_at');
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_CALL => 'Ligação',
            self::TYPE_WHATSAPP => 'WhatsApp',
            self::TYPE_NOTE => 'Nota',
            self::TYPE_TASK => 'Tarefa',
            self::TYPE_STAGE_CHANGE => 'Mudança de estágio',
            self::TYPE_HANDOFF => 'Handoff',
            self::TYPE_IMPORT => 'Importação',
            self::TYPE_CONTRACT => 'Contrato',
            default => ucfirst((string) $this->type),
        };
    }

    public function isTaskOpen(): bool
    {
        return $this->type === self::TYPE_TASK && $this->completed_at === null;
    }
}
