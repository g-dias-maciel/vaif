<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingInvoiceItem extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'billing_invoice_items';

    protected $fillable = [
        'invoice_id',
        'description',
        'quantity',
        'unit_amount_cents',
        'total_cents',
        'sort_order',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_amount_cents' => 'integer',
        'total_cents' => 'integer',
        'sort_order' => 'integer',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(BillingInvoice::class, 'invoice_id');
    }
}
