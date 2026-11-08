<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Single-row finance configuration (id = 1): issuer data, PIX key, invoice
 * prefix and payment instructions used by the cobrança/receipt screens.
 */
class BillingSetting extends Model
{
    protected $table = 'billing_settings';

    protected $primaryKey = 'id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'id',
        'legal_name',
        'tax_id',
        'address',
        'pix_key',
        'invoice_prefix',
        'default_due_day',
        'payment_instructions',
        'receipt_footer',
    ];

    protected $casts = [
        'default_due_day' => 'integer',
    ];

    public static function current(): self
    {
        return self::firstOrCreate(['id' => 1], [
            'invoice_prefix' => 'VAIF',
            'default_due_day' => 10,
        ]);
    }
}
