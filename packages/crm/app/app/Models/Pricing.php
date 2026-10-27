<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pricing extends Model
{
    use HasUuids;

    // The pricing table predates Laravel and only has `created_at`.
    public $timestamps = false;

    protected $table = 'pricing';

    protected $fillable = [
        'id',
        'artist_id',
        'region',
        'placement',
        'body_zone',
        'table_price',
        'session_duration_min',
        'buffer_min',
        'creeps',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class, 'artist_id');
    }
}
