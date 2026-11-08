<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A signed plan attached to a Prospect. This is the source of truth for
 * monthly value and contract dates; invoice generation moves here later.
 */
class ProspectContract extends Model
{
    use HasUuids;

    public const PLAN_MENSAL = 'mensal';

    public const PLAN_SEMESTRAL = 'semestral';

    public const PLAN_TWELVE_MONTHS = 'twelve_months';

    public const PLAN_FULL_SERVICE = 'full_service';

    public const PLAN_MEMBERS_AREA = 'members_area';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ENDED = 'ended';

    public const STATUS_CHURNED = 'churned';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'prospect_contracts';

    protected $fillable = [
        'id',
        'prospect_id',
        'artist_id',
        'plan',
        'monthly_value_cents',
        'total_value_cents',
        'start_date',
        'end_date',
        'status',
        'signed_at',
        'billing_day',
        'next_invoice_at',
        'invoice_status',
        'external_ref',
        'notes',
        'billing_mode',
        'payment_method',
        'mercado_pago_preapproval_id',
        'mercado_pago_customer_id',
        'next_charge_at',
        'notion_page_id',
    ];

    protected $casts = [
        'monthly_value_cents' => 'integer',
        'total_value_cents' => 'integer',
        'start_date' => 'date',
        'end_date' => 'date',
        'signed_at' => 'datetime',
        'billing_day' => 'integer',
        'next_invoice_at' => 'date',
        'next_charge_at' => 'date',
    ];

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class, 'prospect_id');
    }

    public function artist(): BelongsTo
    {
        return $this->belongsTo(Artist::class, 'artist_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(BillingInvoice::class, 'contract_id')->orderByDesc('period_start');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(BillingPayment::class, 'contract_id')->orderByDesc('paid_at');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function isMembersArea(): bool
    {
        return $this->plan === self::PLAN_MEMBERS_AREA;
    }

    public function isCard(): bool
    {
        return $this->billing_mode === 'card';
    }

    public function isPix(): bool
    {
        return $this->billing_mode !== 'card';
    }

    public function billingModeLabel(): string
    {
        return $this->isCard() ? 'Cartão (Mercado Pago)' : 'PIX';
    }

    public function paymentMethodLabel(): string
    {
        return match ($this->payment_method) {
            'credit_card' => 'Cartão de crédito',
            'pix' => 'PIX',
            'boleto' => 'Boleto',
            'transfer' => 'Transferência',
            'cash' => 'Dinheiro',
            default => $this->isCard() ? 'Cartão de crédito' : 'PIX',
        };
    }

    public function nextBillingDate(): ?\Illuminate\Support\Carbon
    {
        return $this->isCard() ? $this->next_charge_at : $this->next_invoice_at;
    }

    public function planLabel(): string
    {
        return match ($this->plan) {
            self::PLAN_MENSAL => 'Mensal',
            self::PLAN_SEMESTRAL => 'Semestral',
            self::PLAN_TWELVE_MONTHS => '12 meses',
            self::PLAN_FULL_SERVICE => 'Full service',
            self::PLAN_MEMBERS_AREA => 'Área de membros (697)',
            default => ucfirst((string) $this->plan),
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'Rascunho',
            self::STATUS_ACTIVE => 'Ativo',
            self::STATUS_ENDED => 'Encerrado',
            self::STATUS_CHURNED => 'Churn',
            default => ucfirst((string) $this->status),
        };
    }

    public function statusTone(): string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE => 'ok',
            self::STATUS_DRAFT => 'pending',
            self::STATUS_CHURNED => 'danger',
            default => 'muted',
        };
    }
}
