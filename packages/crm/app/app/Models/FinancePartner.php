<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A partner who shares the company profit and can be reimbursed for expenses
 * paid from a personal account.
 */
class FinancePartner extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'finance_partners';

    protected $fillable = [
        'id',
        'name',
        'share_bps',
        'active',
    ];

    protected $casts = [
        'share_bps' => 'integer',
        'active' => 'boolean',
    ];

    public function distributions(): HasMany
    {
        return $this->hasMany(FinanceDistribution::class, 'partner_id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(BillingExpense::class, 'paid_by_partner_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function sharePercent(): float
    {
        return round($this->share_bps / 100, 2);
    }

    public function shareLabel(): string
    {
        return rtrim(rtrim(number_format($this->sharePercent(), 2, ',', '.'), '0'), ',').'%';
    }
}
