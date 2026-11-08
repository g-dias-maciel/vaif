<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A staff-only ops/calibration report. Read-only for the model; actions are
 * suggestions until a human approves them in a later phase.
 */
class CopilotReport extends Model
{
    use HasUuids;

    public const KIND_OPS = 'ops';
    public const KIND_CALIBRATION = 'calibration';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_READY = 'ready';
    public const STATUS_FAILED = 'failed';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'copilot_reports';

    protected $fillable = [
        'id',
        'kind',
        'period_key',
        'artist_id',
        'period_start',
        'period_end',
        'status',
        'facts',
        'narrative',
        'findings',
        'model',
        'generated_at',
        'delivered_at',
    ];

    protected $casts = [
        'facts' => 'array',
        'findings' => 'array',
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'generated_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class, 'artist_id');
    }

    public function isReady(): bool
    {
        return $this->status === self::STATUS_READY;
    }

    public function label(): string
    {
        return $this->kind === self::KIND_OPS ? 'Ops diário' : 'Calibração semanal';
    }
}
