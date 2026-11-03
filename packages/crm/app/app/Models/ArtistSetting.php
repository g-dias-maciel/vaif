<?php

namespace App\Models;

use App\Casts\PgTextArray;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArtistSetting extends Model
{
    protected $table = 'artist_settings';

    protected $primaryKey = 'artist_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'artist_id',
        'display_name',
        'specialties',
        'excluded_styles',
        'full_selling',
        'blocked_numbers',
        'floor_pct',
        'deposit_type',
        'deposit_value',
        'pix_key',
        'instagram_handle',
        'whatsapp_number',
        'address',
        'city',
        'telegram_group_id',
        'working_hours',
        'ai_active_hours',
        'pricing_extras',
    ];

    protected $casts = [
        'specialties' => PgTextArray::class,
        'excluded_styles' => PgTextArray::class,
        'blocked_numbers' => PgTextArray::class,
        'full_selling' => 'boolean',
        'working_hours' => 'array',
        'ai_active_hours' => 'array',
        'pricing_extras' => 'array',
        'floor_pct' => 'decimal:2',
    ];

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class, 'artist_id');
    }
}
