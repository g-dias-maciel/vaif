<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The rolling AI summary of a Lead's conversation.
 *
 * Not a Note: it has no author, is updated in place, and is visible to the
 * Artist as a convenience rather than a record of human work.
 */
class LeadSummary extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $primaryKey = 'lead_id';

    protected $table = 'lead_summaries';

    protected $fillable = [
        'lead_id',
        'body',
        'message_count',
        'model',
        'generated_at',
    ];

    protected $casts = [
        'generated_at' => 'datetime',
        'message_count' => 'integer',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'lead_id');
    }
}
