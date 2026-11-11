<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A cost (saída) assigned to a competence month. `pending` + past due date is
 * shown as overdue; paying it flips the status to `paid`.
 */
class BillingExpense extends Model
{
    use HasUuids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_CANCELLED = 'cancelled';

    public const CATEGORY_ADS = 'ads';

    public const CATEGORY_SOFTWARE = 'software';

    public const CATEGORY_PAYROLL = 'payroll';

    public const CATEGORY_CONTRACTORS = 'contractors';

    public const CATEGORY_TAXES = 'taxes';

    public const CATEGORY_OFFICE = 'office';

    public const CATEGORY_OTHER = 'other';

    public const RECURRENCE_ONE_OFF = 'one_off';

    public const RECURRENCE_MONTHLY = 'monthly';

    public const METHOD_PIX = 'pix';

    public const METHOD_CREDIT_CARD = 'credit_card';

    public const METHOD_BOLETO = 'boleto';

    public const METHOD_TRANSFER = 'transfer';

    public const METHOD_CASH = 'cash';

    public const METHOD_OTHER = 'other';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'billing_expenses';

    protected $fillable = [
        'id',
        'description',
        'vendor',
        'category',
        'amount_cents',
        'competence_month',
        'due_date',
        'paid_at',
        'status',
        'payment_method',
        'recurrence',
        'recurrence_key',
        'notes',
        'created_by',
        'notion_page_id',
        'copied_from_id',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'competence_month' => 'date',
        'due_date' => 'date',
        'paid_at' => 'date',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForMonth(Builder $query, Carbon $month): Builder
    {
        return $query->whereBetween('competence_month', [
            $month->copy()->startOfMonth()->toDateString(),
            $month->copy()->endOfMonth()->toDateString(),
        ]);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING)
            ->whereDate('due_date', '<', now()->toDateString());
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function isOverdue(): bool
    {
        return $this->status === self::STATUS_PENDING
            && $this->due_date !== null
            && $this->due_date->isPast();
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => 'A pagar',
            self::STATUS_PAID => 'Paga',
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

    public function categoryLabel(): string
    {
        return match ($this->category) {
            self::CATEGORY_ADS => 'Anúncios',
            self::CATEGORY_SOFTWARE => 'Ferramentas',
            self::CATEGORY_PAYROLL => 'Salários / pró-labore',
            self::CATEGORY_CONTRACTORS => 'Freelancers',
            self::CATEGORY_TAXES => 'Impostos',
            self::CATEGORY_OFFICE => 'Estrutura',
            default => 'Outros',
        };
    }

    public function paymentMethodLabel(): string
    {
        return match ($this->payment_method) {
            self::METHOD_PIX => 'PIX',
            self::METHOD_CREDIT_CARD => 'Cartão',
            self::METHOD_BOLETO => 'Boleto',
            self::METHOD_TRANSFER => 'Transferência',
            self::METHOD_CASH => 'Dinheiro',
            default => '—',
        };
    }

    public function recurrenceLabel(): string
    {
        return $this->recurrence === self::RECURRENCE_MONTHLY ? 'Mensal' : 'Avulsa';
    }

    public function monthLabel(): string
    {
        return $this->competence_month?->format('m/Y') ?? '—';
    }

    /**
     * @return array<string, string>
     */
    public static function categoryOptions(): array
    {
        return [
            self::CATEGORY_ADS => 'Anúncios',
            self::CATEGORY_SOFTWARE => 'Ferramentas',
            self::CATEGORY_PAYROLL => 'Salários / pró-labore',
            self::CATEGORY_CONTRACTORS => 'Freelancers',
            self::CATEGORY_TAXES => 'Impostos',
            self::CATEGORY_OFFICE => 'Estrutura',
            self::CATEGORY_OTHER => 'Outros',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function methodOptions(): array
    {
        return [
            self::METHOD_PIX => 'PIX',
            self::METHOD_CREDIT_CARD => 'Cartão',
            self::METHOD_BOLETO => 'Boleto',
            self::METHOD_TRANSFER => 'Transferência',
            self::METHOD_CASH => 'Dinheiro',
            self::METHOD_OTHER => 'Outro',
        ];
    }
}
