<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Optional override for a month's Guardado (saved amount). A null saved_cents
 * means "derive it from profit − profit distributions". Historical months are
 * seeded with 0 because Notion's distributions were not imported.
 */
class FinanceMonth extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'finance_months';

    protected $fillable = [
        'id',
        'month',
        'saved_cents',
        'notes',
        'closed_at',
    ];

    protected $casts = [
        'month' => 'date',
        'saved_cents' => 'integer',
        'closed_at' => 'datetime',
    ];

    public static function savedFor(Carbon $month): ?int
    {
        $row = static::query()
            ->whereDate('month', $month->copy()->startOfMonth()->toDateString())
            ->first();

        return $row?->saved_cents;
    }
}
