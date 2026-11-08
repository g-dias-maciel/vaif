<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A PIX (or manual) cobrança. Card clients use BillingPayment directly, with
 * no invoice: Mercado Pago recurring charges are revenue rows.
 */
class BillingInvoice extends Model
{
    use HasUuids;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENT = 'sent';

    public const STATUS_PAID = 'paid';

    public const STATUS_OVERDUE = 'overdue';

    public const STATUS_CANCELLED = 'cancelled';

    public const METHOD_PIX = 'pix';

    public const METHOD_BOLETO = 'boleto';

    public const METHOD_TRANSFER = 'transfer';

    public const METHOD_CASH = 'cash';

    public const METHOD_OTHER = 'other';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'billing_invoices';

    protected $fillable = [
        'id',
        'number',
        'contract_id',
        'prospect_id',
        'period_start',
        'period_end',
        'issue_date',
        'due_date',
        'amount_cents',
        'paid_cents',
        'status',
        'payment_method',
        'external_ref',
        'pix_copy_paste',
        'pix_qr_code',
        'notes',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end' => 'date',
        'issue_date' => 'date',
        'due_date' => 'date',
        'amount_cents' => 'integer',
        'paid_cents' => 'integer',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(ProspectContract::class, 'contract_id');
    }

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class, 'prospect_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BillingInvoiceItem::class, 'invoice_id')->orderBy('sort_order');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(BillingPayment::class, 'invoice_id')->orderByDesc('paid_at');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_DRAFT, self::STATUS_SENT, self::STATUS_OVERDUE]);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OVERDUE);
    }

    public function balanceCents(): int
    {
        return max(0, $this->amount_cents - $this->paid_cents);
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID || $this->balanceCents() === 0;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isOverdue(): bool
    {
        if ($this->isPaid() || $this->isCancelled()) {
            return false;
        }

        return $this->status === self::STATUS_OVERDUE
            || ($this->due_date !== null && $this->due_date->isPast());
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => 'Rascunho',
            self::STATUS_SENT => 'Enviada',
            self::STATUS_PAID => 'Paga',
            self::STATUS_OVERDUE => 'Atrasada',
            self::STATUS_CANCELLED => 'Cancelada',
            default => ucfirst((string) $this->status),
        };
    }

    public function statusTone(): string
    {
        return match (true) {
            $this->status === self::STATUS_PAID => 'ok',
            $this->isOverdue() => 'danger',
            $this->status === self::STATUS_CANCELLED => 'muted',
            default => 'pending',
        };
    }

    public function paymentMethodLabel(): string
    {
        return match ($this->payment_method) {
            self::METHOD_PIX => 'PIX',
            self::METHOD_BOLETO => 'Boleto',
            self::METHOD_TRANSFER => 'Transferência',
            self::METHOD_CASH => 'Dinheiro',
            default => 'Outro',
        };
    }

    public function periodLabel(): string
    {
        return $this->period_start->format('d/m/Y').' — '.$this->period_end->format('d/m/Y');
    }
}
