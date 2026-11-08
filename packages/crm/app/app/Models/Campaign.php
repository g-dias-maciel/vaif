<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A marketing campaign that feeds the internal sales pipeline.
 *
 * This is VAIF's own acquisition campaign, not an Artist's ad account.
 */
class Campaign extends Model
{
    use HasUuids;

    public const CHANNEL_META = 'meta';

    public const CHANNEL_GOOGLE = 'google';

    public const CHANNEL_ORGANIC = 'organic';

    public const CHANNEL_REFERRAL = 'referral';

    public const CHANNEL_EVENT = 'event';

    public const CHANNEL_OTHER = 'other';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_ENDED = 'ended';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'campaigns';

    protected $fillable = [
        'id',
        'name',
        'channel',
        'status',
        'starts_on',
        'ends_on',
        'budget_cents',
        'spend_cents',
        'utm_campaign',
        'creative_note',
        'notes',
    ];

    protected $casts = [
        'starts_on' => 'date',
        'ends_on' => 'date',
        'budget_cents' => 'integer',
        'spend_cents' => 'integer',
    ];

    public function prospects(): HasMany
    {
        return $this->hasMany(Prospect::class, 'campaign_id');
    }

    public function getCostPerLeadCentsAttribute(): ?int
    {
        $leads = $this->prospects()->count();

        return $leads > 0 ? intdiv((int) $this->spend_cents, $leads) : null;
    }
}
