<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A monthly partner payout: either the share of the month's profit or the
 * reimbursement of company expenses the partner paid personally.
 */
class FinanceDistribution extends Model
{
    use HasUuids;

    public const KIND_PROFIT = 'profit';

    public const KIND_REIMBURSEMENT = 'reimbursement';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'finance_distributions';

    protected $fillable = [
        'id',
        'partner_id',
        'competence_month',
        'kind',
        'amount_cents',
        'paid_at',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'competence_month' => 'date',
        'amount_cents' => 'integer',
        'paid_at' => 'date',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(FinancePartner::class, 'partner_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(BillingExpense::class, 'reimbursed_distribution_id');
    }

    public function scopeForMonth(Builder $query, $month): Builder
    {
        return $query->whereBetween('competence_month', [
            $month->copy()->startOfMonth()->toDateString(),
            $month->copy()->endOfMonth()->toDateString(),
        ]);
    }

    public function scopeProfit(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_PROFIT);
    }

    public function scopeReimbursement(Builder $query): Builder
    {
        return $query->where('kind', self::KIND_REIMBURSEMENT);
    }

    public function kindLabel(): string
    {
        return $this->kind === self::KIND_REIMBURSEMENT ? 'Reembolso' : 'Lucro';
    }
}
