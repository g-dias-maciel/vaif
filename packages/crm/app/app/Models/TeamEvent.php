<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Carbon\Carbon;

/**
 * Internal meeting on the admin calendar. Sales calls from prospects are
 * rendered from `prospects.booked_call_at`, not stored here.
 */
class TeamEvent extends Model
{
    use HasUuids;

    public const TYPE_SALES = 'sales';

    public const TYPE_STRATEGY = 'strategy';

    public const TYPE_OTHER = 'other';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'team_events';

    protected $fillable = [
        'id',
        'title',
        'type',
        'starts_at',
        'ends_at',
        'location',
        'meeting_url',
        'notes',
        'owner_id',
        'prospect_id',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class, 'prospect_id');
    }

    public function scopeBetween(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->where('starts_at', '<', $to)
            ->where('ends_at', '>=', $from);
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_SALES => 'Vendas',
            self::TYPE_STRATEGY => 'Estratégia',
            default => 'Outro',
        };
    }

    public function typeTone(): string
    {
        return match ($this->type) {
            self::TYPE_SALES => 'sales',
            self::TYPE_STRATEGY => 'strategy',
            default => 'other',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function typeOptions(): array
    {
        return [
            self::TYPE_SALES => 'Vendas',
            self::TYPE_STRATEGY => 'Estratégia',
            self::TYPE_OTHER => 'Outro',
        ];
    }
}
