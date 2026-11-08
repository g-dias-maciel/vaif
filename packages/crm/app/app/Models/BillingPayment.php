<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money received. `invoice_id` is null for card charges (Mercado Pago
 * recurring) and for any revenue that has no cobrança.
 */
class BillingPayment extends Model
{
    use HasUuids;

    public const METHOD_PIX = 'pix';

    public const METHOD_CREDIT_CARD = 'credit_card';

    public const METHOD_BOLETO = 'boleto';

    public const METHOD_TRANSFER = 'transfer';

    public const METHOD_CASH = 'cash';

    public const METHOD_OTHER = 'other';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_MERCADO_PAGO = 'mercado_pago';

    public const SOURCE_N8N = 'n8n';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'billing_payments';

    protected $fillable = [
        'id',
        'invoice_id',
        'contract_id',
        'prospect_id',
        'amount_cents',
        'method',
        'paid_at',
        'reference',
        'external_ref',
        'source',
        'notes',
        'recorded_by',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'paid_at' => 'date',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(BillingInvoice::class, 'invoice_id');
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(ProspectContract::class, 'contract_id');
    }

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class, 'prospect_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function scopeCard(Builder $query): Builder
    {
        return $query->where('method', self::METHOD_CREDIT_CARD);
    }

    public function scopeReceivedBetween(Builder $query, $from, $to): Builder
    {
        return $query->whereBetween('paid_at', [$from, $to]);
    }

    public function methodLabel(): string
    {
        return match ($this->method) {
            self::METHOD_PIX => 'PIX',
            self::METHOD_CREDIT_CARD => 'Cartão',
            self::METHOD_BOLETO => 'Boleto',
            self::METHOD_TRANSFER => 'Transferência',
            self::METHOD_CASH => 'Dinheiro',
            default => 'Outro',
        };
    }

    public function sourceLabel(): string
    {
        return match ($this->source) {
            self::SOURCE_MERCADO_PAGO => 'Mercado Pago',
            self::SOURCE_N8N => 'n8n',
            default => 'Manual',
        };
    }
}
