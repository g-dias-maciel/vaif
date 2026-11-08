<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Internal sales pipeline stage. Seeded by the 0033 migration.
 */
class ProspectStage extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $primaryKey = 'key';

    protected $table = 'prospect_stages';

    protected $fillable = [
        'key',
        'label_pt',
        'sort_order',
        'is_open',
        'is_won',
        'is_lost',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_open' => 'boolean',
        'is_won' => 'boolean',
        'is_lost' => 'boolean',
    ];

    public function prospects(): HasMany
    {
        return $this->hasMany(Prospect::class, 'stage_key', 'key');
    }
}
