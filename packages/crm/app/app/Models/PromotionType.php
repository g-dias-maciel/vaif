<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A centrally defined promotion mechanic (e.g. "Leve 2 pelo valor de 2x").
 * Artists never edit these; they only carry the Artist-specific settings.
 */
class PromotionType extends Model
{
    protected $table = 'promotion_types';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    // The table only has `created_at`.
    public $timestamps = false;

    protected $fillable = [
        'key',
        'name',
        'description',
        'config_spec',
        'active',
    ];

    protected $casts = [
        'config_spec' => 'array',
        'active' => 'boolean',
    ];

    public function promotions(): HasMany
    {
        return $this->hasMany(Promotion::class, 'type_key', 'key');
    }
}
