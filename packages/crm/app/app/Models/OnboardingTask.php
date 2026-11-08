<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A D0–D7 task created during the Prospect → Artist handoff.
 */
class OnboardingTask extends Model
{
    use HasUuids;

    public const KEY_WELCOME = 'welcome';

    public const KEY_BRIEFING = 'briefing';

    public const KEY_STRATEGY_CALL = 'strategy_call';

    public const KEY_CREATIVES = 'creatives';

    public const KEY_SETUP = 'setup';

    public const KEY_GO_LIVE = 'go_live';

    public const KEY_MILESTONE_60 = 'milestone_60';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'onboarding_tasks';

    protected $fillable = [
        'id',
        'artist_id',
        'key',
        'title',
        'due_at',
        'completed_at',
        'owner_id',
        'notes',
    ];

    protected $casts = [
        'due_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class, 'artist_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('completed_at');
    }
}
