<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A manual payment (deposit, balance or refund). `method` and `external_ref`
 * are the seam for the future automatic-Pix product; today the Artist confirms
 * the deposit by hand in the CRM.
 */
class Payment extends Model
{
    use HasUuids;

    protected $table = 'payments';

    public $timestamps = false;

    protected $fillable = [
        'id',
        'artist_id',
        'project_id',
        'kind',
        'amount_cents',
        'currency',
        'status',
        'method',
        'external_ref',
        'requested_at',
        'confirmed_at',
        'notes',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'requested_at' => 'datetime',
        'confirmed_at' => 'datetime',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'project_id');
    }

    public function isConfirmed(): bool
    {
        return $this->status === 'confirmed';
    }
}
