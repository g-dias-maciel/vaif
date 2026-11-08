<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A signed plan attached to a Prospect. This is the source of truth for
 * monthly value and contract dates; invoice generation moves here later.
 */
class ProspectContract extends Model
{
    use HasUuids;

    public const PLAN_MENSAL = 'mensal';

    public const PLAN_SEMESTRAL = 'semestral';

    public const PLAN_TWELVE_MONTHS = 'twelve_months';

    public const PLAN_FULL_SERVICE = 'full_service';

    public const PLAN_MEMBERS_AREA = 'members_area';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ENDED = 'ended';

    public const STATUS_CHURNED = 'churned';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'prospect_contracts';

    protected $fillable = [
        'id',
        'prospect_id',
        'artist_id',
        'plan',
        'monthly_value_cents',
        'total_value_cents',
        'start_date',
        'end_date',
        'status',
        'signed_at',
        'billing_day',
        'next_invoice_at',
        'invoice_status',
        'external_ref',
        'notes',
    ];

    protected $casts = [
        'monthly_value_cents' => 'integer',
        'total_value_cents' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'signed_at' => 'datetime',
        'billing_day' => 'integer',
        'next_invoice_at' => 'date',
    ];

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class, 'prospect_id');
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class, 'artist_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function isMembersArea(): bool
    {
        return $this->plan === self::PLAN_MEMBERS_AREA;
    }
}
